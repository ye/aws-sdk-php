<?php
namespace Aws;

use Aws\Exception\AwsException;
use Aws\Api\Service;
use Aws\Credentials\CredentialsInterface;
use GuzzleHttp\Command\AbstractClient;
use GuzzleHttp\Command\Command;
use GuzzleHttp\Command\CommandInterface;
use GuzzleHttp\Command\CommandTransaction;
use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Command\Event\ProcessEvent;

/**
 * Default AWS client implementation
 */
class AwsClient extends AbstractClient implements AwsClientInterface
{
    /** @var CredentialsInterface AWS credentials */
    private $credentials;

    /** @var array Default command options */
    private $defaults;

    /** @var string */
    private $region;

    /** @var string */
    private $endpoint;

    /** @var Service */
    private $api;

    /** @var string */
    private $commandException;

    /** @var callable */
    private $errorParser;

    /** @var callable */
    private $serializer;

    /** @var callable */
    private $signatureProvider;

    /** @var callable */
    private $defaultSignatureListener;

    /**
     * Get an array of client constructor arguments used by the client.
     *
     * @return array
     */
    public static function getArguments()
    {
        return ClientResolver::getDefaultArguments();
    }

    /**
     * The client constructor accepts the following options:
     *
     * @param array $config Client configuration options.
     *
     * @throws \InvalidArgumentException if any required options are missing
     */
    public function __construct(array $config)
    {
        $service = $this->parseClass();
        $resolver = new ClientResolver(static::getArguments());
        if (!isset($config['service'])) {
            $config['service'] = $service;
        }
        $config = $resolver->resolve($this, $config);
        $this->api = $config['api'];
        $this->serializer = $config['serializer'];
        $this->errorParser = $config['error_parser'];
        $this->signatureProvider = $config['signature_provider'];
        $this->endpoint = $config['endpoint'];
        $this->credentials = $config['credentials'];
        $this->defaults = $config['defaults'];
        $this->region = isset($config['region']) ? $config['region'] : null;
        $this->applyParser();
        $this->initSigners($config);
        parent::__construct($config['client'], $config['config']);
        $this->postConstruct($config);
    }

    /**
     * @deprecated
     * @return static
     */
    public static function factory(array $config = [])
    {
        return new static($config);
    }

    public function getCredentials()
    {
        return $this->credentials;
    }

    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function getRegion()
    {
        return $this->region;
    }

    public function getApi()
    {
        return $this->api;
    }

    /**
     * Executes an AWS command.
     *
     * @param CommandInterface $command Command to execute
     *
     * @return mixed Returns the result of the command
     * @throws AwsException when an error occurs during transfer
     */
    public function execute(CommandInterface $command)
    {
        try {
            return parent::execute($command);
        } catch (AwsException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Wrap other uncaught exceptions for consistency
            $exceptionClass = $this->commandException;
            throw new $exceptionClass(
                sprintf('Uncaught exception while executing %s::%s - %s',
                    get_class($this),
                    $command->getName(),
                    $e->getMessage()),
                new CommandTransaction($this, $command),
                $e
            );
        }
    }

    public function getCommand($name, array $args = [])
    {
        // Fail fast if the command cannot be found in the description.
        if (!isset($this->api['operations'][$name])) {
            $name = ucfirst($name);
            if (!isset($this->api['operations'][$name])) {
                throw new \InvalidArgumentException("Operation not found: $name");
            }
        }

        // Merge in default configuration options.
        $args += $this->getConfig('defaults');

        if (isset($args['@future'])) {
            $future = $args['@future'];
            unset($args['@future']);
        } else {
            $future = false;
        }

        return new Command($name, $args + $this->defaults, [
            'emitter' => clone $this->getEmitter(),
            'future' => $future
        ]);
    }

    public function getIterator($name, array $args = [])
    {
        $config = $this->api->getPaginatorConfig($name);
        if (!$config['result_key']) {
            throw new \UnexpectedValueException(sprintf(
                'There are no resources to iterate for the %s operation of %s',
                $name, $this->api['serviceFullName']
            ));
        }

        $key = is_array($config['result_key'])
            ? $config['result_key'][0]
            : $config['result_key'];

        if ($config['output_token'] && $config['input_token']) {
            return $this->getPaginator($name, $args)->search($key);
        }

        $result = $this->getCommand($name, $args)->search($key);

        return new \ArrayIterator((array) $result);
    }

    public function getPaginator($name, array $args = [], array $config = [])
    {
        $config += $this->api->getPaginatorConfig($name);

        return new ResultPaginator($this, $name, $args, $config);
    }

    public function waitUntil($name, array $args = [], array $config = [])
    {
        // Create the waiter. If async, then waiting begins immediately.
        $config += $this->api->getWaiterConfig($name);
        $waiter = new Waiter($this, $name, $args, $config);

        // If async, return the future, for access to then()/wait()/cancel().
        if (!empty($args['@future'])) {
            return $waiter;
        }

        // For synchronous waiting, call wait() and don't return anything.
        $waiter->wait();
    }

    /**
     * Creates AWS specific exceptions.
     *
     * {@inheritdoc}
     *
     * @return AwsException
     */
    public function createCommandException(CommandTransaction $transaction)
    {
        // Throw AWS exceptions as-is
        if ($transaction->exception instanceof AwsException) {
            return $transaction->exception;
        }

        // Set default values (e.g., for non-RequestException)
        $url = null;
        $transaction->context['aws_error'] = [];
        $serviceError = $transaction->exception->getMessage();

        if ($transaction->exception instanceof RequestException) {
            $url = $transaction->exception->getRequest()->getUrl();
            if ($response = $transaction->exception->getResponse()) {
                $parser = $this->errorParser;
                // Add the parsed response error to the exception.
                $transaction->context['aws_error'] = $parser($response);
                // Only use the AWS error code if the parser could parse response.
                if (!$transaction->context->getPath('aws_error/type')) {
                    $serviceError = $transaction->exception->getMessage();
                } else {
                    // Create an easy to read error message.
                    $serviceError = trim($transaction->context->getPath('aws_error/code')
                        . ' (' . $transaction->context->getPath('aws_error/type')
                        . ' error): ' . $transaction->context->getPath('aws_error/message'));
                }
            }
        }

        $exceptionClass = $this->commandException;
        return new $exceptionClass(
            sprintf('Error executing %s::%s() on "%s"; %s',
                get_class($this),
                lcfirst($transaction->command->getName()),
                $url,
                $serviceError),
            $transaction,
            $transaction->exception
        );
    }

    final protected function createFutureResult(CommandTransaction $transaction)
    {
        return new FutureResult(
            $transaction->response->then(function () use ($transaction) {
                return $transaction->result;
            }),
            [$transaction->response, 'wait'],
            [$transaction->response, 'cancel']
        );
    }

    final protected function serializeRequest(CommandTransaction $trans)
    {
        $fn = $this->serializer;
        $request = $fn($trans);

        // Note: We can later update this to allow custom per/operation
        // signers, by checking the corresponding operation for a
        // signatureVersion override and attaching a different listener.
        $request->getEmitter()->on(
            'before',
            $this->defaultSignatureListener,
            RequestEvents::SIGN_REQUEST
        );

        return $request;
    }

    /**
     * Get the signature_provider function of the client.
     *
     * @return callable
     */
    final protected function getSignatureProvider()
    {
        return $this->signatureProvider;
    }

    /**
     * Provides a hook for clients to use after the constructor. This hook
     * allows subclasses access to the resolved configuration array.
     *
     * @param array $args
     */
    protected function postConstruct(array $args) {}

    private function initSigners(array $config)
    {
        $conf = $config['config'];
        $fn = $this->signatureProvider;
        $defaultSigner = $fn(
            $conf['signature_version'],
            $this->api->getSigningName(),
            $this->region
        );
        $this->defaultSignatureListener = function (BeforeEvent $e) use ($defaultSigner) {
            $defaultSigner->signRequest(
                $e->getRequest(),
                $this->credentials
            );
        };
    }

    private function parseClass()
    {
        $klass = get_class($this);

        if ($klass == __CLASS__) {
            $this->commandException = 'Aws\Exception\AwsException';
            return [__NAMESPACE__, ''];
        }

        $parts = explode('\\', $klass);
        $service = str_replace('Client', '', array_pop($parts));
        $ns = implode('\\', $parts);
        $this->commandException = "{$ns}\\Exception\\{$service}Exception";

        return strtolower($service);
    }

    private function applyParser()
    {
        $parser = Service::createParser($this->api);
        $this->getEmitter()->on(
            'process',
            function (ProcessEvent $e) use ($parser) {
                // Guard against exceptions and injected results.
                if ($e->getException() || $e->getResult()) {
                    return;
                }

                // Ensure a response exists in order to parse.
                $response = $e->getResponse();
                if (!$response) {
                    throw new \RuntimeException('No response was received.');
                }

                $e->setResult($parser($e->getCommand(), $response));
            }
        );
    }
}
