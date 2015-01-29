<?php
namespace Aws\DynamoDb;

use GuzzleHttp\ToArrayInterface;

/**
 * Special object to represent a DynamoDB set (SS/NS/BS) value.
 */
class SetValue implements \JsonSerializable, \ArrayAccess, \Countable, \IteratorAggregate, ToArrayInterface
{
    /** @var array Values in the set. */
    private $values;

    /** @var string Set type. One of "SS", "NS", or "BS. */
    private $type;

    /**
     * @param string $type   One of "SS", "NS", or "BS.
     * @param array  $values Values in the set.
     */
    public function __construct($type, array $values = [])
    {
        $this->type = $type;
        if (!in_array($this->type, ['SS', 'NS', 'BS'], true)) {
            throw new \InvalidArgumentException(
                'Invalid set type. Must be BS, NS, or SS'
            );
        }

        foreach ($values as $value) {
            $this->offsetSet(null, $value);
        }
    }

    /**
     * Get the set type.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Get the values formatted for DynamoDB.
     *
     * @return array
     */
    public function getValues()
    {
        if (empty($this->values)) {
            throw new \RuntimeException('DynamoDB does not allow empty sets.');
        }

        return array_map('strval', array_keys($this->values));
    }

    /**
     * Get the values formatted for PHP and JSON.
     *
     * @return array
     */
    public function toArray()
    {
        $values = array_keys($this->values);
        if ($this->type === 'BS') {
            foreach ($values as &$value) {
                $value = new BinaryValue($value);
            }
        }

        return $values;
    }

    public function offsetSet($offset, $value)
    {
        if ($offset !== null) {
            throw new \OutOfBoundsException('Sets do not have indexes or keys
                like normal arrays. Only the `$set[] = $value` syntax is valid.'
            );
        }

        $this->values[(string) $value] = true;
    }

    public function offsetGet($offset)
    {
        return isset($this->values[$offset]) ? $offset : null;
    }

    public function offsetExists($offset)
    {
        return isset($this->values[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->values[$offset]);
    }

    public function count()
    {
        return count($this->values);
    }

    public function getIterator()
    {
        return new \ArrayIterator($this->values);
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }
}
