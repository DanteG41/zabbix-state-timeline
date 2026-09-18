<?php declare(strict_types = 0);
/*
** State timeline widget module for Zabbix 7.0
** Copyright (C) 2026 Mamedaliev Kirill
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


/*
 * Tests of the conversion of history values to state runs. The conversion has no Zabbix frontend dependencies.
 *
 *   docker run --rm -v "$PWD":/m -w /m php:8.3-cli-alpine php tests/CStateTimelineTest.php
 */

require_once __DIR__.'/../includes/CStateRunsBuilder.php';
require_once __DIR__.'/../includes/CStateTimelineHelper.php';

use Modules\StateTimeline\Includes\CStateTimelineHelper as H;

$failures = 0;
$tests = 0;

function check(string $name, $expected, $actual): void {
	global $failures, $tests;

	$tests++;

	if ($expected !== $actual) {
		$failures++;
		echo "FAIL: $name\n  expected: ".json_encode($expected)."\n  actual:   ".json_encode($actual)."\n";
	}
}

function values(array $samples): array {
	$result = [];

	foreach ($samples as [$clock, $value]) {
		$result[] = ['clock' => (string) $clock, 'ns' => '0', 'value' => (string) $value];
	}

	return $result;
}

function runs(array $runs): array {
	$result = [];

	foreach ($runs['t'] as $i => $t) {
		$value = $runs['v'][$i] === null ? null : $runs['values'][$runs['v'][$i]];
		$result[] = [$t, $runs['s'][$i], $value];
	}

	return $result;
}

// States.
check('state 0', H::STATE_0, H::getState(0, null));
check('state 1', H::STATE_1, H::getState(1, null));
check('state negative', H::STATE_1, H::getState(-1, null));
check('state float', H::STATE_1, H::getState(0.5, null));
check('state threshold below', H::STATE_0, H::getState(0.4, 0.5));
check('state threshold equal', H::STATE_1, H::getState(0.5, 0.5));
check('range 0', H::STATE_0, H::getRangeState(0, 0, null));
check('range 1', H::STATE_1, H::getRangeState(1, 5, null));
check('range mixed', H::STATE_MIXED, H::getRangeState(0, 1, null));
check('range threshold 1', H::STATE_1, H::getRangeState(3, 5, 3));
check('range threshold 0', H::STATE_0, H::getRangeState(1, 2.9, 3));
check('range threshold mixed', H::STATE_MIXED, H::getRangeState(1, 3, 3));

// Rare values: 12:00 = 1, 12:01 = 1, 12:02 = 1, 12:37 = 0 is one change at 12:37, not an interpolation.
$from = 43200;
$r = H::buildRunsFromValues(values([[$from, 1], [$from + 60, 1], [$from + 120, 1], [$from + 2220, 0]]), null,
	$from, $from + 3600, null, null
);
check('rare values', [[43200.0, 1, '1'], [45420.0, 0, '0']], runs($r));
check('rare values end', 46800.0, $r['end']);
check('rare values before', false, $r['before']);

// No value before the first sample: no data until the first value.
$r = H::buildRunsFromValues(values([[$from + 600, 1]]), null, $from, $from + 3600, null, null);
check('leading no data', [[43200.0, -1, null], [43800.0, 1, '1']], runs($r));

// Value before the period: state is known from the period start.
$r = H::buildRunsFromValues(values([[$from + 600, 0]]), ['clock' => $from - 30, 'value' => '1'], $from,
	$from + 3600, null, null
);
check('previous value', [[43200.0, 1, '1'], [43800.0, 0, '0']], runs($r));
check('previous value before', true, $r['before']);

// No values at all, but a previous value: the state lasts the whole period, without artificial changes.
$r = H::buildRunsFromValues([], ['clock' => $from - 30, 'value' => '1'], $from, $from + 3600, null, null);
check('previous value only', [[43200.0, 1, '1']], runs($r));

// Sub-second precision.
$r = H::buildRunsFromValues([
	['clock' => (string) ($from + 10), 'ns' => '250000000', 'value' => '1'],
	['clock' => (string) ($from + 10), 'ns' => '750000000', 'value' => '0']
], null, $from, $from + 3600, null, null);
check('nanoseconds', [[43200.0, -1, null], [43210.25, 1, '1'], [43210.75, 0, '0']], runs($r));

// Float values: equal values are merged, different non-zero values are separate runs of the same state.
$r = H::buildRunsFromValues(values([[$from, '1.0000'], [$from + 60, '1'], [$from + 120, '2.5']]), null, $from,
	$from + 3600, null, null
);
check('float values', [[43200.0, 1, '1'], [43320.0, 1, '2.5']], runs($r));

// Missing data: values older than "no data after" are not valid.
$r = H::buildRunsFromValues(values([[$from, 1], [$from + 60, 1], [$from + 1800, 1]]), null, $from, $from + 3600,
	300, null
);
check('no data after', [[43200.0, 1, '1'], [43560.0, -1, null], [45000.0, 1, '1'], [45300.0, -1, null]],
	runs($r)
);

// Value at the period start replaces the previous value.
$r = H::buildRunsFromValues(values([[$from, 0]]), ['clock' => $from - 30, 'value' => '1'], $from, $from + 3600,
	null, null
);
check('value at period start', [[43200.0, 0, '0']], runs($r));

// Threshold.
$r = H::buildRunsFromValues(values([[$from, 10], [$from + 60, 90], [$from + 120, 95]]), null, $from,
	$from + 3600, null, 50.0
);
check('threshold', [[43200.0, 0, '10'], [43260.0, 1, '90'], [43320.0, 1, '95']], runs($r));

// Aggregated values: 3600 s over 60 pixels, 60 s per pixel.
$rows = [
	['clock' => (string) ($from + 20), 'min' => '1', 'max' => '1'],
	['clock' => (string) ($from + 90), 'min' => '1', 'max' => '1'],
	['clock' => (string) ($from + 150), 'min' => '0', 'max' => '1'],
	['clock' => (string) ($from + 210), 'min' => '0', 'max' => '0'],
	['clock' => (string) ($from + 1210), 'min' => '0', 'max' => '0']
];
$r = H::buildRunsFromBuckets($rows, null, $from, $from + 3600, $from + 3600, 60, 0, null, null);
check('buckets', [[43200.0, 1, '1'], [43350.0, 2, ['0', '1']], [43410.0, 0, '0']], runs($r));

$r = H::buildRunsFromBuckets(array_reverse($rows), null, $from, $from + 3600, $from + 3600, 60, 0, 120, null);
check('buckets no data after', [[43200.0, 1, '1'], [43350.0, 2, ['0', '1']], [43410.0, 0, '0'],
	[43530.0, -1, null], [44370.0, 0, '0'], [44530.0, -1, null]
], runs($r));

// Period end is not later than now: runs never start after the end.
$r = H::buildRunsFromValues(values([[$from, 1]]), null, $from, $from + 100, 30, null);
check('end', [[43200.0, 1, '1'], [43230.0, -1, null]], runs($r));

$r = H::buildRunsFromValues([], null, $from, $from + 100, null, null);
check('empty', [[43200.0, -1, null]], runs($r));

echo "$tests tests, $failures failures\n";

exit($failures > 0 ? 1 : 0);
