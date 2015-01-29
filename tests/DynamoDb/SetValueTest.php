<?php
namespace Aws\Tests\DynamoDb;

use Aws\DynamoDb\SetValue;

/**
 * @covers Aws\DynamoDb\SetValue
 */
class SetValueTest extends \PHPUnit_Framework_TestCase
{
    public function testSetValueCanBeFormattedAndSerialized()
    {
        $ss = new SetValue('SS', ['foo']);
        $this->assertEquals('SS', $ss->getType());
        $this->assertEquals(['foo'], $ss->getValues());
        $this->assertEquals(['foo'], $ss->toArray());
        $this->assertEquals('["foo"]', json_encode($ss));

        $ns = new SetValue('NS', [3]);
        $this->assertEquals('NS', $ns->getType());
        $this->assertEquals(["3"], $ns->getValues());
        $this->assertEquals([3], $ns->toArray());
        $this->assertEquals('[3]', json_encode($ns));

        $bs = new SetValue('BS', ['foo']);
        $this->assertEquals('BS', $bs->getType());
        $this->assertEquals(['foo'], $bs->getValues());
        $this->assertEquals(['foo'], $bs->toArray());
        $this->assertEquals('["foo"]', json_encode($bs));
    }

    /** @expectedException \InvalidArgumentException */
    public function testErrorIfInvalidType()
    {
        $set = new SetValue('MS', [['foo', 'bar'], [1, 2]]);
    }

    /** @expectedException \RuntimeException */
    public function testErrorIfEmptyWhenGettingValues()
    {
        $set = new SetValue('SS', []);
        $set->getValues();
    }

    public function testBehavesLikeASet()
    {
        $set = new SetValue('SS', []);
        $set[] = 'foo';
        $set[] = 'bar';
        $set[] = 'baz';

        $this->assertTrue(isset($set['foo']));
        $this->assertFalse(isset($set['gone']));
        $this->assertEquals('foo', $set['foo']);
        $this->assertNull($set['gone']);
        $this->assertEquals(3, count($set));
        $this->assertEquals(3, iterator_count($set));

        $set[] = 'foo';
        $this->assertEquals(3, count($set));

        $set[] = 'fizz';
        $this->assertEquals(4, count($set));

        unset($set['bar']);
        $this->assertEquals(3, count($set));
    }

    /** @expectedException \OutOfBoundsException */
    public function testErrorIfSetValuesByIndexOrKey()
    {
        $set = new SetValue('SS', []);
        $set['foo'] = 'bar';
    }
}
