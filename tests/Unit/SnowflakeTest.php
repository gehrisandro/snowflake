<?php

namespace Glhd\Bits\Tests\Unit;

use Glhd\Bits\Bits;
use Glhd\Bits\Contracts\ResolvesSequences;
use Glhd\Bits\Factories\BitsFactory;
use Glhd\Bits\Tests\ResolvesSequencesFromMemory;
use Glhd\Bits\Tests\TestCase;
use Illuminate\Support\Facades\Date;

class SnowflakeTest extends TestCase
{
	use ResolvesSequencesFromMemory;
	
	public function test_it_generates_unique_ids(): void
	{
		$exists = [];
		$iterations = 10_000;
		
		for ($i = 0; $i < $iterations; $i++) {
			$exists[Bits::make()->id()] = true;
		}
		
		$this->assertCount($iterations, $exists);
	}
	
	public function test_it_generates_snowflakes_in_the_expected_format(): void
	{
		$snowflake = Bits::make()->id();
		
		$this->assertGreaterThan(0, $snowflake);
		$this->assertLessThanOrEqual(9_223_372_036_854_775_807, $snowflake);
	}
	
	public function test_it_generates_snowflakes_with_the_correct_datacenter_and_worker_ids(): void
	{
		$factory1 = new BitsFactory(now(), random_int(0, 7), random_int(0, 7));
		$factory2 = new BitsFactory(now(), random_int(8, 15), random_int(8, 15));
		
		$snowflake1 = $factory1->make();
		$snowflake2 = $factory2->make();
		
		$this->assertEquals($factory1->datacenter_id, $snowflake1->datacenter_id);
		$this->assertEquals($factory1->worker_id, $snowflake1->worker_id);
		
		$this->assertEquals($factory2->datacenter_id, $snowflake2->datacenter_id);
		$this->assertEquals($factory2->worker_id, $snowflake2->worker_id);
	}
	
	public function test_it_can_parse_an_existing_snowflake(): void
	{
		$snowflake = Bits::fromId(1537200202186752);
		
		$this->assertEquals(0, $snowflake->datacenter_id);
		$this->assertEquals(0, $snowflake->worker_id);
		$this->assertEquals(0, $snowflake->sequence);
	}
	
	public function test_it_generates_predictable_snowflakes(): void
	{
		Date::setTestNow(now());
		
		$sequence = 0;
		
		$factory = new BitsFactory(now(), 1, 15, 3, new class($sequence) implements ResolvesSequences {
			public function __construct(public int &$sequence)
			{
			}
			
			public function next(int $timestamp): int
			{
				return $this->sequence++;
			}
		});
		
		$snowflake_at_epoch1 = $factory->make();
		
		$this->assertEquals($snowflake_at_epoch1->id(), 0b0000000000000000000000000000000000000000000000101111000000000000);
		$this->assertEquals($snowflake_at_epoch1->timestamp, 0);
		$this->assertEquals($snowflake_at_epoch1->datacenter_id, 1);
		$this->assertEquals($snowflake_at_epoch1->worker_id, 15);
		$this->assertEquals($snowflake_at_epoch1->sequence, 0);
		
		$snowflake_at_epoch2 = $factory->make();
		
		$this->assertEquals($snowflake_at_epoch2->id(), 0b0000000000000000000000000000000000000000000000101111000000000001);
		$this->assertEquals($snowflake_at_epoch2->timestamp, 0);
		$this->assertEquals($snowflake_at_epoch2->datacenter_id, 1);
		$this->assertEquals($snowflake_at_epoch2->worker_id, 15);
		$this->assertEquals($snowflake_at_epoch2->sequence, 1);
		
		Date::setTestNow(now()->addMillisecond());
		$snowflake_at_1ms = $factory->make();
		
		$this->assertEquals($snowflake_at_1ms->id(), 0b0000000000000000000000000000000000000000010000101111000000000010);
		$this->assertEquals($snowflake_at_1ms->timestamp, 1);
		$this->assertEquals($snowflake_at_1ms->datacenter_id, 1);
		$this->assertEquals($snowflake_at_1ms->worker_id, 15);
		$this->assertEquals($snowflake_at_1ms->sequence, 2);
		
		Date::setTestNow(now()->addMillisecond());
		$snowflake_at_2ms = $factory->make();
		
		$this->assertEquals($snowflake_at_2ms->id(), 0b0000000000000000000000000000000000000000100000101111000000000011);
		$this->assertEquals($snowflake_at_2ms->timestamp, 2);
		$this->assertEquals($snowflake_at_2ms->datacenter_id, 1);
		$this->assertEquals($snowflake_at_2ms->worker_id, 15);
		$this->assertEquals($snowflake_at_2ms->sequence, 3);
	}
	
	public function test_it_can_generate_a_snowflake_for_a_given_timestamp(): void
	{
		Date::setTestNow(now());
		
		$factory = new BitsFactory(now(), 31, 31, 3, new class() implements ResolvesSequences {
			public function next(int $timestamp): int
			{
				return 4095;
			}
		});
		
		$a = $factory->makeFromTimestampForQuery(now()->addMinutes(30));
		
		// FIXME: Should sequence be considered?
		
		$this->assertEquals($a->id(), 0b0000000000000000000001101101110111010000000000000000000000000000);
		$this->assertEquals($a->timestamp, 1_800_000);
		$this->assertEquals($a->datacenter_id, 0);
		$this->assertEquals($a->worker_id, 0);
		$this->assertEquals($a->sequence, 0);
		
		$b = $factory->makeFromTimestampForQuery(now()->addMinutes(60));
		
		$this->assertEquals($b->id(), 0b0000000000000000000011011011101110100000000000000000000000000000);
		$this->assertEquals($b->timestamp, 3_600_000);
		$this->assertEquals($b->datacenter_id, 0);
		$this->assertEquals($b->worker_id, 0);
		$this->assertEquals($b->sequence, 0);
		
		$minutes = ($b->timestamp - $a->timestamp) / 60_000;
		$this->assertEquals(30, $minutes);
	}
}
