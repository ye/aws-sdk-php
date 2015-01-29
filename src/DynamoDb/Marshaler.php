<?php
namespace Aws\DynamoDb;

use GuzzleHttp\Stream\StreamInterface;

/**
 * Marshals and unmarshals JSON documents and PHP arrays into DynamoDB items.
 */
class Marshaler
{
    /**  @var callable Called if the marshaler encounters an invalid value. */
    private $errorHandler;

    /**
     * Instantiates the DynamoDB Marshaler.
     *
     * The `$errorHandler` allows the user to provide custom logic to handle
     * invalid values (e.g., an empty string). The signature of this function
     * should look like `function ($type, $value)`. It should return an array
     * formatted like `[TYPE => VALUE]`, representing a valid DynamoDB attribute
     * value. If the function cannot handle the value, it should return `null`.
     *
     * @param callable $errorHandler A function that is called if the marshaler
     *                               encounters a value it can't marshal.
     */
    public function __construct(callable $errorHandler = null)
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * Creates a special object to represent a DynamoDB binary (B) value.
     *
     * @param mixed $value A binary value compatible with Guzzle streams.
     *
     * @return BinaryValue
     * @see GuzzleHttp\Stream\Stream::factory
     */
    public function binary($value)
    {
        return $value instanceof BinaryValue ? $value : new BinaryValue($value);
    }

    /**
     * Creates a special object to represent a DynamoDB set (SS/NS/BS) value.
     *
     * @param array  $values The values of the set.
     *
     * @return BinaryValue
     *
     */
    public function set(array $values)
    {
        if (empty($values)) {
            throw new \InvalidArgumentException('Sets cannot be empty.');
        }

        $type = key($this->marshalValue($values[0])) . 'S';

        return new SetValue($type, $values);
    }

    /**
     * Marshal a JSON document from a string to a DynamoDB item.
     *
     * The result is an array formatted in the proper parameter structure
     * required by the DynamoDB API for items.
     *
     * @param string $json A valid JSON document.
     *
     * @return array Item formatted for DynamoDB.
     * @throws \InvalidArgumentException if the JSON is invalid.
     */
    public function marshalJson($json)
    {
        $data = json_decode($json);
        if (!($data instanceof \stdClass)) {
            throw new \InvalidArgumentException(
                'The JSON document must be valid and be an object at its root.'
            );
        }

        return current($this->marshalValue($data));
    }

    /**
     * Marshal a native PHP array of data to a DynamoDB item.
     *
     * The result is an array formatted in the proper parameter structure
     * required by the DynamoDB API for items.
     *
     * @param array|\stdClass $item An associative array of data.
     *
     * @return array Item formatted for DynamoDB.
     */
    public function marshalItem($item)
    {
        return current($this->marshalValue($item));
    }

    /**
     * Marshal a native PHP value into a DynamoDB attribute value.
     *
     * The result is an associative array that is formatted in the proper
     * `[TYPE => VALUE]` parameter structure required by the DynamoDB API.
     *
     * @param mixed $value A scalar, array, or `stdClass` value.
     *
     * @return array Attribute formatted for DynamoDB.
     * @throws \UnexpectedValueException if the value cannot be marshaled.
     */
    public function marshalValue($value)
    {
        $type = gettype($value);
        if ($type === 'string' && $value !== '') {
            $type = 'S';
        } elseif ($type === 'integer' || $type === 'double') {
            $type = 'N';
            $value = (string) $value;
        } elseif ($type === 'boolean') {
            $type = 'BOOL';
        } elseif ($type === 'NULL') {
            $type = 'NULL';
            $value = true;
        } elseif ($value instanceof SetValue) {
            $type = $value->getType();
            $value = $value->getValues();
        } elseif ($type === 'array'
            || $value instanceof \Traversable
            || $value instanceof \stdClass
        ) {
            $type = $value instanceof \stdClass ? 'M' : 'L';
            $data = [];
            $expectedIndex = 0;
            foreach ($value as $k => $v) {
                $data[$k] = $this->marshalValue($v);
                if ($type === 'L' && (!is_int($k) || $k != $expectedIndex++)) {
                    $type = 'M';
                }
            }
            $value = $data;
        } elseif (is_resource($value)
            || $value instanceof BinaryValue
            || $value instanceof StreamInterface
        ) {
            $type = 'B';
            $value = (string) $this->binary($value);
        } else {
            if (($fn = $this->errorHandler) && ($result = $fn($type, $value))) {
                return $result;
            }
            $type = $type === 'object' ? get_class($value) : $type;
            throw new \UnexpectedValueException(
                "Marshaling error: encountered unexpected type \"{$type}\"."
            );
        }

        return [$type => $value];
    }

    /**
     * Unmarshal a document (item) from a DynamoDB operation result into a JSON
     * document string.
     *
     * @param array $data            Item/document from a DynamoDB result.
     * @param int   $jsonEncodeFlags Flags to use with `json_encode()`.
     *
     * @return string
     */
    public function unmarshalJson(array $data, $jsonEncodeFlags = 0)
    {
        return json_encode(
            $this->unmarshalValue(['M' => $data], true),
            $jsonEncodeFlags
        );
    }

    /**
     * Unmarshal an item from a DynamoDB operation result into a native PHP
     * array. If you set $mapAsObject to true, then a stdClass value will be
     * returned instead.
     *
     * @param array $data Item from a DynamoDB result.
     *
     * @return array|\stdClass
     */
    public function unmarshalItem(array $data)
    {
        return $this->unmarshalValue(['M' => $data]);
    }

    /**
     * Unmarshal a value from a DynamoDB operation result into a native PHP
     * value. Will return a scalar, array, or (if you set $mapAsObject to true)
     * stdClass value.
     *
     * @param array $value       Value from a DynamoDB result.
     * @param bool  $mapAsObject Whether maps should be represented as stdClass.
     *
     * @return mixed
     * @throws \UnexpectedValueException
     */
    public function unmarshalValue(array $value, $mapAsObject = false)
    {
        list($type, $value) = each($value);
        switch ($type) {
            case 'S':
            case 'BOOL':
                return $value;
            case 'NULL':
                return null;
            case 'N':
                // Use type coercion to unmarshal numbers to int/float.
                return $value + 0;
            case 'M':
                if ($mapAsObject) {
                    $data = new \stdClass;
                    foreach ($value as $k => $v) {
                        $data->$k = $this->unmarshalValue($v, $mapAsObject);
                    }
                    return $data;
                }
            // Else, unmarshal M the same way as L.
            case 'L':
                foreach ($value as &$v) {
                    $v = $this->unmarshalValue($v, $mapAsObject);
                }
                return $value;
            case 'B':
                return new BinaryValue($value);
            case 'SS':
            case 'NS':
            case 'BS':
                return new SetValue($type, $value);
        }

        throw new \UnexpectedValueException("Unexpected type: {$type}.");
    }
}
