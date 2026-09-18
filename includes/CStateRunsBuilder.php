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


namespace Modules\StateTimeline\Includes;

/**
 * Builds a step function of item values ("runs") within a period.
 *
 * Result is a set of parallel arrays: run start times (t), run states (s) and indexes of run values (v) in the list of
 * distinct values (values). A run lasts until the start of the next run, the last run lasts until the period end.
 */
class CStateRunsBuilder {

	private const STATE_NODATA = -1;

	private float $time_from;
	private float $end;
	private ?int $nodata_after;

	// Time of the last value, used to detect missing data.
	private ?float $last_clock = null;

	private array $t = [];
	private array $s = [];
	private array $v = [];

	private array $values = [];
	private array $value_index = [];

	/**
	 * @param float    $time_from     Period start.
	 * @param float    $end           Period end.
	 * @param int|null $nodata_after  Period after the last value, after which the state is unknown. Null: a value is
	 *                                valid until the next value.
	 */
	public function __construct(float $time_from, float $end, ?int $nodata_after) {
		$this->time_from = $time_from;
		$this->end = $end;
		$this->nodata_after = $nodata_after;

		$this->push($time_from, self::STATE_NODATA, null);
	}

	/**
	 * Add a value.
	 *
	 * @param float        $start  Time from which the value is valid.
	 * @param float        $clock  Time the value was collected at (the last one, for aggregated values).
	 * @param int          $state
	 * @param string|array $value  Value or [min, max] range of aggregated values.
	 */
	public function addValue(float $start, float $clock, int $state, $value): void {
		if ($this->nodata_after !== null && $this->last_clock !== null
				&& $start - $this->last_clock > $this->nodata_after) {
			$this->push($this->last_clock + $this->nodata_after, self::STATE_NODATA, null);
		}

		$this->push(max($start, $this->time_from), $state, $value);

		$this->last_clock = $this->last_clock === null ? $clock : max($this->last_clock, $clock);
	}

	/**
	 * Whether the state is unknown at the period start.
	 */
	public function startsWithGap(): bool {
		return $this->s && $this->s[0] == self::STATE_NODATA;
	}

	public function getRuns(): array {
		if ($this->nodata_after !== null && $this->last_clock !== null) {
			$this->push($this->last_clock + $this->nodata_after, self::STATE_NODATA, null);
		}

		return [
			't' => $this->t,
			's' => $this->s,
			'v' => $this->v,
			'values' => $this->values,
			'end' => $this->end
		];
	}

	private function push(float $time, int $state, $value): void {
		$time = round($time, 3);

		if ($time >= $this->end && $this->t) {
			return;
		}

		$index = $value === null ? null : $this->getValueIndex($value);
		$last = count($this->t) - 1;

		// A run of zero length is replaced.
		if ($last >= 0 && $this->t[$last] >= $time) {
			array_pop($this->t);
			array_pop($this->s);
			array_pop($this->v);
			$time = $this->t ? max($time, $this->t[$last - 1]) : $this->time_from;
			$last--;
		}

		if ($last >= 0 && $this->s[$last] == $state && $this->v[$last] === $index) {
			return;
		}

		$this->t[] = $time;
		$this->s[] = $state;
		$this->v[] = $index;
	}

	private function getValueIndex($value): int {
		$key = is_array($value) ? implode("\n", $value) : $value;

		if (!array_key_exists($key, $this->value_index)) {
			$this->value_index[$key] = count($this->values);
			$this->values[] = $value;
		}

		return $this->value_index[$key];
	}
}
