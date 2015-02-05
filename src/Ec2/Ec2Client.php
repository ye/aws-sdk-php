<?php
namespace Aws\Ec2;

use Aws\AwsClient;

/**
 * This client is used to interact with **Amazon EC2**.
 */
class Ec2Client extends AwsClient
{
    protected function postConstruct(array $args)
    {
        $this->getEmitter()->attach(new CopySnapshotSubscriber(
            $args['endpoint_provider']
        ));
    }
}
