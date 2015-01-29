<?php
namespace Aws\Tests\DynamoDb;

use Aws\DynamoDb\BinaryValue;

/**
 * @covers Aws\DynamoDb\BinaryValue
 */
class BinaryValueTest extends \PHPUnit_Framework_TestCase
{
    public function testBinaryValueCanBeFormattedAndSerialized()
    {
        $resource = fopen('php://temp', 'w+');
        fwrite($resource, 'foo');
        fseek($resource, 0);

        $binary = new BinaryValue($resource);
        $this->assertEquals('foo', (string) $binary);
        $this->assertEquals('"foo"', json_encode($binary));
    }
}
