<?php
namespace Aws\Tests\DynamoDb;

use Aws\DynamoDb\NumberValue;

/**
 * @covers Aws\DynamoDb\NumberValue
 */
class NumberValueTest extends \PHPUnit_Framework_TestCase
{
    public function testBinaryValueCanBeFormattedAndSerialized()
    {
        $number = new NumberValue('99999999999999999999');
        $this->assertEquals('99999999999999999999', (string) $number);
        $this->assertEquals('"99999999999999999999"', json_encode($number));
    }
}
