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


/**
 * State timeline renderer.
 *
 * Rows of visible items are drawn on a single canvas, so the number of DOM elements does not depend on the number of
 * history values or state changes. Mouse interaction (helper line, row highlight, selection box) is drawn on an SVG
 * overlay using the styles of the Graph widget, so moving the mouse does not redraw the rows.
 *
 * Data of all items is kept in memory: scrolling between items does not request data from the server.
 */
class CStateTimeline {

	static STATE_NODATA = -1;
	static STATE_0 = 0;
	static STATE_1 = 1;
	static STATE_MIXED = 2;

	// Row styles, same values as in WidgetForm.
	static STYLE_SEGMENTS = 0;
	static STYLE_FLAT = 1;
	static STYLE_CAPSULES = 2;
	static STYLE_UPTIME_BARS = 3;
	static STYLE_GLOSSY = 4;
	static STYLE_TRACK = 5;
	static STYLE_HATCHED = 6;

	// Kinds of pixels (bit masks of states present in a pixel).
	static KIND_NONE = 0;
	static KIND_1 = 1;
	static KIND_0 = 2;
	static KIND_MIXED = 3;
	static KIND_NODATA = 4;

	static AXIS_HEIGHT = 20;
	static ROW_HEIGHT_MAX = 28;
	static LABEL_WIDTH_MIN = 60;
	static LABEL_WIDTH_MAX_RATIO = 0.4;
	static LABEL_PADDING = 10;

	// Item names are not shown in lower rows (they are still shown in the tooltip).
	static LABEL_ROW_HEIGHT_MIN = 9;

	// Minimum width of a state span (in pixels) at both sides of a state change to draw a state change marker.
	static MARKER_MIN_SPAN = 3;

	static SCROLL_ANIMATION_MS = 200;

	// Width of ticks of the "Uptime bars" style and space between them.
	static UPTIME_TICK_WIDTH = 9;
	static UPTIME_TICK_GAP = 3;

	// Width of the vertical scrollbar, including the space between the rows and the scrollbar.
	static SCROLLBAR_WIDTH = 16;

	/**
	 * @param {HTMLElement} container
	 * @param {Object}      callbacks
	 *        {function}    callbacks.onZoom           Called with {from_offset, to_offset} (seconds).
	 *        {function}    callbacks.onZoomOut
	 *        {function}    callbacks.onInteractionStart  Selection box started, widget updates must be paused.
	 *        {function}    callbacks.onInteractionEnd
	 */
	constructor(container, callbacks) {
		this._container = container;
		this._callbacks = callbacks;

		this._data = null;
		this._offset = 0;
		this._animation = null;
		this._animation_target = null;
		this._layout = null;
		this._redraw_frame = null;
		this._pointer = null;
		this._hintbox = null;
		this._hintbox_target = null;
		this._sbox = null;
		this._masks = null;
		this._text_measure = document.createElement('canvas').getContext('2d');

		this._build();
		this._registerEvents();
	}

	/**
	 * Width of the timeline (in pixels) for loading data. Available after the first draw.
	 *
	 * @returns {number|null}
	 */
	getTimelineWidth() {
		return this._layout !== null ? this._layout.plot_width : null;
	}

	/**
	 * Set new data. Position of the slider is kept.
	 *
	 * @param {Object} data  Widget response "timeline" object.
	 */
	setData(data) {
		this._data = data;

		for (const row of data.rows) {
			row.state_bounds = null;
		}

		this._stopAnimation();
		this._offset = this._clampOffset(Math.round(this._offset));

		this._notice_text.textContent = this._getNoticeText();

		this._makePatterns();
		this.resize();
	}

	/**
	 * Recalculate the layout and redraw. Data is not reloaded.
	 */
	resize() {
		if (this._data === null) {
			return;
		}

		this._layout = this._calculateLayout();
		this._offset = this._clampOffset(this._offset);

		this._draw();
		this._updatePointer();
	}

	/**
	 * Hide the tooltip and cancel the selection box.
	 */
	resetInteraction() {
		this._pointer = null;
		this._destroySBox();
		this._hidePointer();
	}

	destroy() {
		this._stopAnimation();
		this._destroySBox();
		this._hideHint();

		if (this._redraw_frame !== null) {
			cancelAnimationFrame(this._redraw_frame);
		}

		for (const [target, type, listener] of this._listeners) {
			target.removeEventListener(type, listener);
		}

		this._listeners = [];
		this._root.remove();
	}

	_build() {
		this._root = this._createElement('div', 'state-timeline');

		// Status line: notice about not displayed items and the range of displayed items.
		this._notice = this._createElement('div', 'state-timeline-notice');
		this._notice_text = this._createElement('span', 'state-timeline-notice-text');
		this._nav_info = this._createElement('span', 'state-timeline-notice-range');
		this._notice.append(this._notice_text, this._nav_info);
		this._notice.hidden = true;

		this._main = this._createElement('div', 'state-timeline-main');
		this._labels = this._createElement('div', 'state-timeline-labels');
		this._plot = this._createElement('div', 'state-timeline-plot');

		this._canvas = document.createElement('canvas');
		this._canvas.classList.add('state-timeline-canvas');

		this._svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		this._svg.classList.add('state-timeline-overlay');

		this._svg_axis = this._createSvgElement('g', {class: 'state-timeline-axis'});
		this._svg_row = this._createSvgElement('rect', {class: 'state-timeline-row-highlight', width: 0, height: 0});
		this._svg_segment = this._createSvgElement('path', {class: 'state-timeline-segment-highlight', d: ''});
		this._svg_helper = this._createSvgElement('line', {class: 'svg-helper', x1: -10, x2: -10, y1: 0, y2: 0});
		this._svg_selection = this._createSvgElement('rect', {class: 'svg-graph-selection', width: 0, height: 0});
		this._svg_selection_text = this._createSvgElement('text', {class: 'svg-graph-selection-text'});

		this._svg.append(this._svg_axis, this._svg_row, this._svg_segment, this._svg_helper, this._svg_selection,
			this._svg_selection_text
		);

		this._plot.append(this._canvas, this._svg);

		this._nav_track = this._createElement('div', 'state-timeline-scrollbar');
		this._nav_track.tabIndex = 0;
		this._nav_track.setAttribute('role', 'scrollbar');
		this._nav_track.setAttribute('aria-orientation', 'vertical');
		this._nav_thumb = this._createElement('div', 'state-timeline-scrollbar-thumb');
		this._nav_track.append(this._nav_thumb);
		this._nav_track.hidden = true;

		this._main.append(this._labels, this._plot, this._nav_track);

		this._root.append(this._notice, this._main);
		this._container.append(this._root);

		this._label_pool = [];
	}

	_registerEvents() {
		this._listeners = [];

		const on = (target, type, listener, options) => {
			target.addEventListener(type, listener, options);
			this._listeners.push([target, type, listener]);
		};

		on(this._plot, 'mousemove', (e) => {
			this._pointer = {client_x: e.clientX, client_y: e.clientY, event: e};
			this._updatePointer();
		});

		on(this._plot, 'mouseleave', () => {
			this._pointer = null;
			this._updatePointer();
		});

		on(this._plot, 'mousedown', (e) => this._startSBox(e));

		on(this._plot, 'dblclick', (e) => {
			if (this._data !== null && this._data.sbox) {
				e.preventDefault();
				this._hideHint();
				this._callbacks.onZoomOut();
			}
		});

		on(this._labels, 'mouseover', (e) => {
			const link = e.target.closest('.state-timeline-label a');

			if (link !== null && link.scrollWidth > link.clientWidth) {
				const row = this._data.rows[link.dataset.index];
				const content = document.createElement('div');

				content.classList.add('svg-graph-hintbox');
				content.append(this._createElement('div', 'header', row.label),
					this._createElement('div', 'state-timeline-hintbox-key', row.key)
				);

				this._showHint(e, link, content);
			}
		});

		on(this._labels, 'mouseout', (e) => {
			const link = e.target.closest('.state-timeline-label a');

			if (link !== null && !link.contains(e.relatedTarget)) {
				this._hideHint();
			}
		});

		on(this._main, 'wheel', (e) => {
			if (!this._isScrollable() || e.ctrlKey) {
				return;
			}

			const delta = Math.abs(e.deltaY) >= Math.abs(e.deltaX) ? e.deltaY : 0;
			const max_offset = this._data.rows.length - this._layout.visible_rows;
			const target = this._animation_target ?? this._offset;

			// At the first or the last item the dashboard is scrolled instead.
			if (delta === 0 || (delta < 0 && target <= 0) || (delta > 0 && target >= max_offset)) {
				return;
			}

			e.preventDefault();

			const lines = e.deltaMode === WheelEvent.DOM_DELTA_PIXEL ? Math.abs(delta) / 40 : Math.abs(delta);

			this._scrollTo(target + Math.sign(delta) * Math.max(1, Math.round(lines)), true);
		}, {passive: false});

		on(this._nav_track, 'keydown', (e) => {
			const steps = {
				ArrowUp: -1,
				ArrowDown: 1,
				PageUp: -this._layout.visible_rows,
				PageDown: this._layout.visible_rows
			};

			if (e.key in steps) {
				this._scrollTo(Math.round(this._animation_target ?? this._offset) + steps[e.key], true);
			}
			else if (e.key === 'Home' || e.key === 'End') {
				this._scrollTo(e.key === 'Home' ? 0 : this._data.rows.length, true);
			}
			else {
				return;
			}

			e.preventDefault();
		});

		on(this._nav_track, 'pointerdown', (e) => this._startThumbDrag(e));
	}

	// Layout.

	_calculateLayout() {
		const rows_count = this._data.rows.length;
		const visible_rows = Math.max(1, Math.min(rows_count, this._data.items_per_page));
		const scrollable = rows_count > visible_rows;

		// The status line and the scrollbar must be shown before measuring the available space.
		this._nav_track.hidden = !scrollable;
		this._notice.hidden = this._notice_text.textContent === '' && !scrollable;

		const width = Math.max(1, this._main.clientWidth);
		const height = Math.max(1, this._main.clientHeight);

		const rows_height_available = Math.max(visible_rows, height - CStateTimeline.AXIS_HEIGHT);
		const row_height = Math.min(CStateTimeline.ROW_HEIGHT_MAX, rows_height_available / visible_rows);

		let label_width = 0;

		if (row_height >= CStateTimeline.LABEL_ROW_HEIGHT_MIN) {
			this._text_measure.font = getComputedStyle(this._labels).font;

			for (const row of this._data.rows) {
				label_width = Math.max(label_width, this._text_measure.measureText(row.label).width);
			}

			label_width = Math.ceil(Math.min(Math.max(label_width + CStateTimeline.LABEL_PADDING,
				CStateTimeline.LABEL_WIDTH_MIN), width * CStateTimeline.LABEL_WIDTH_MAX_RATIO
			));
		}

		const plot_width = Math.max(1, width - label_width - (scrollable ? CStateTimeline.SCROLLBAR_WIDTH : 0));

		return {
			width,
			height,
			label_width,
			plot_width,
			visible_rows,
			scrollable,
			row_height,
			rows_height: row_height * visible_rows,
			// Vertical space between rows.
			row_gap: row_height >= 6 ? Math.max(1, Math.round(row_height * 0.15)) : 0
		};
	}

	_clampOffset(offset) {
		const max_offset = this._layout !== null
			? this._data.rows.length - this._layout.visible_rows
			: this._data.rows.length - Math.min(this._data.rows.length, this._data.items_per_page);

		return Math.min(Math.max(0, offset), Math.max(0, max_offset));
	}

	_isScrollable() {
		return this._layout !== null && this._layout.scrollable;
	}

	// Drawing.

	_draw() {
		const {plot_width, rows_height, label_width} = this._layout;

		this._labels.style.width = `${label_width}px`;
		this._plot.style.left = `${label_width}px`;
		this._plot.style.width = `${plot_width}px`;
		this._nav_track.style.height = `${rows_height}px`;

		const ratio = window.devicePixelRatio || 1;

		this._canvas.width = Math.round(plot_width * ratio);
		this._canvas.height = Math.round(rows_height * ratio);
		this._canvas.style.width = `${plot_width}px`;
		this._canvas.style.height = `${rows_height}px`;

		this._svg.setAttribute('width', plot_width);
		this._svg.setAttribute('height', rows_height + CStateTimeline.AXIS_HEIGHT);

		this._grid = this._getTimeGrid();

		this._drawAxis();
		this._drawRows();
		this._updateNav();
	}

	_scheduleDraw() {
		if (this._redraw_frame === null) {
			this._redraw_frame = requestAnimationFrame(() => {
				this._redraw_frame = null;
				this._drawRows();
				this._updateNav();
				this._updatePointer();
			});
		}
	}

	_drawRows() {
		const {plot_width, rows_height, row_height, row_gap, visible_rows} = this._layout;
		const ctx = this._canvas.getContext('2d');
		const ratio = this._canvas.width / plot_width;

		ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
		ctx.clearRect(0, 0, plot_width, rows_height);

		// Vertical grid lines, same positions as in the X axis.
		ctx.save();
		ctx.strokeStyle = this._data.theme.grid;
		ctx.lineWidth = 1;
		ctx.setLineDash([2, 2]);
		ctx.beginPath();

		for (const x of this._grid.keys()) {
			ctx.moveTo(Math.round(x) + 0.5, 0);
			ctx.lineTo(Math.round(x) + 0.5, rows_height);
		}

		ctx.stroke();
		ctx.restore();

		const first = Math.floor(this._offset);
		const shift = (this._offset - first) * row_height;
		const last = Math.min(this._data.rows.length - 1, Math.ceil(this._offset + visible_rows) - 1);

		// Rows are drawn in device pixels, so that state changes are sharp vertical edges at any display scaling.
		ctx.setTransform(1, 0, 0, 1, 0, 0);
		this._ratio = ratio;

		if (this._masks === null || this._masks.length !== this._canvas.width) {
			this._masks = new Uint8Array(this._canvas.width);
		}

		for (let index = first; index <= last; index++) {
			const y = (index - first) * row_height - shift + row_gap / 2;
			const y0 = Math.round(y * ratio);

			this._drawRow(ctx, this._data.rows[index], y0, Math.round((y + row_height - row_gap) * ratio) - y0);
		}

		this._updateLabels(first, last, shift);
	}

	/**
	 * Draw a single row. Runs are merged per pixel first, so that the number of drawing operations is limited by the
	 * width of the timeline, and short state changes are always visible (at least 1 pixel wide).
	 */
	_drawRow(ctx, row, y, height) {
		const timeline = row.timeline;

		if (timeline === null || timeline.t.length === 0) {
			return;
		}

		const {from_ts, to_ts} = this._data.time_period;
		const width = this._masks.length;
		const scale = width / (to_ts - from_ts);
		const masks = this._masks;
		const count = timeline.t.length;

		// Bit masks of states present in each pixel.
		const BIT = {
			[CStateTimeline.STATE_1]: CStateTimeline.KIND_1,
			[CStateTimeline.STATE_0]: CStateTimeline.KIND_0,
			[CStateTimeline.STATE_MIXED]: CStateTimeline.KIND_MIXED,
			[CStateTimeline.STATE_NODATA]: CStateTimeline.KIND_NODATA
		};

		masks.fill(0);

		for (let i = 0; i < count; i++) {
			const x0 = (timeline.t[i] - from_ts) * scale;
			const x1 = ((i + 1 < count ? timeline.t[i + 1] : timeline.end) - from_ts) * scale;

			if (x1 < 0 || x0 >= width) {
				continue;
			}

			// A pixel belongs to the run covering its center, so a single state change is a sharp vertical edge.
			// Runs shorter than a pixel still take one pixel: several of them in a pixel make it "mixed".
			let p0 = Math.max(0, Math.round(x0));
			let p1 = Math.min(width, Math.round(x1)) - 1;

			if (p1 < p0) {
				p0 = Math.min(width - 1, Math.max(0, Math.floor(x0)));
				p1 = p0;
			}

			const bit = BIT[timeline.s[i]];

			for (let p = p0; p <= p1; p++) {
				masks[p] |= bit;
			}
		}

		// A pixel with a state and missing data is shown as the state.
		for (let p = 0; p < width; p++) {
			if (masks[p] & 3) {
				masks[p] &= 3;
			}
		}

		let span_start = 0;
		const spans = [];

		for (let p = 1; p <= width; p++) {
			if (p === width || masks[p] !== masks[span_start]) {
				spans.push([span_start, p, masks[span_start]]);
				span_start = p;
			}
		}

		const draw = {
			[CStateTimeline.STYLE_SEGMENTS]: this._drawSegments,
			[CStateTimeline.STYLE_FLAT]: this._drawFlat,
			[CStateTimeline.STYLE_CAPSULES]: this._drawCapsules,
			[CStateTimeline.STYLE_UPTIME_BARS]: this._drawUptimeBars,
			[CStateTimeline.STYLE_GLOSSY]: this._drawGlossy,
			[CStateTimeline.STYLE_TRACK]: this._drawTrack,
			[CStateTimeline.STYLE_HATCHED]: this._drawHatched
		}[this._data.style] ?? this._drawSegments;

		ctx.save();
		draw.call(this, ctx, spans.filter(([, , kind]) => kind !== CStateTimeline.KIND_NONE), y, height, scale);
		ctx.restore();
	}

	// Row styles. Spans are [start, end, kind] in device pixels, without empty spans.

	/**
	 * Solid blocks with state change markers.
	 */
	_drawFlat(ctx, spans, y, height) {
		for (const [start, end, kind] of spans) {
			this._fillKind(ctx, kind, start, y, end - start, height);
		}

		ctx.fillStyle = this._data.theme.text;
		ctx.globalAlpha = 0.6;

		for (let i = 1; i < spans.length; i++) {
			const [prev_start, prev_end, prev_kind] = spans[i - 1];
			const [start, end, kind] = spans[i];

			if (CStateTimeline.isState(prev_kind) && CStateTimeline.isState(kind)
					&& prev_end - prev_start >= CStateTimeline.MARKER_MIN_SPAN * this._ratio
					&& end - start >= CStateTimeline.MARKER_MIN_SPAN * this._ratio) {
				ctx.fillRect(start, y, Math.max(1, Math.round(this._ratio)), height);
			}
		}
	}

	/**
	 * Rounded segments with a gap between states, a border and optional state label with duration inside.
	 */
	_drawSegments(ctx, spans, y, height, scale) {
		const gap = Math.max(1, Math.round(this._ratio));
		const radius = 3 * this._ratio;

		spans.forEach(([start, end, kind], index) => {
			const left = start + (index > 0 ? gap : 0);
			const right = end - (index < spans.length - 1 ? gap : 0);

			if (right - left < 3 * gap || kind === CStateTimeline.KIND_MIXED) {
				this._fillKind(ctx, kind, start, y, end - start, height);

				return;
			}

			CStateTimeline.roundRect(ctx, left, y, right - left, height, radius);

			if (kind === CStateTimeline.KIND_NODATA) {
				ctx.fillStyle = this._nodata_pattern;
				ctx.fill();

				return;
			}

			const color = this._kindColor(kind);

			ctx.globalAlpha = 0.85;
			ctx.fillStyle = color;
			ctx.fill();
			ctx.globalAlpha = 1;
			ctx.lineWidth = gap;
			ctx.strokeStyle = color;
			CStateTimeline.roundRect(ctx, left + gap / 2, y + gap / 2, right - left - gap, height - gap, radius);
			ctx.stroke();

			if (this._data.segment_labels) {
				this._drawSegmentLabel(ctx, kind, left, right, y, height, (end - start) / scale);
			}
		});
	}

	_drawSegmentLabel(ctx, kind, left, right, y, height, duration) {
		// Same font size in all rows: the height of rows in device pixels differs by rounding.
		const font_size = Math.min(11, Math.floor(this._layout.row_height - this._layout.row_gap) - 3);

		if (font_size < 8) {
			return;
		}

		const state = this._data.states[kind === CStateTimeline.KIND_1 ? CStateTimeline.STATE_1 : CStateTimeline.STATE_0];
		const padding = 5 * this._ratio;

		ctx.font = `bold ${Math.round(font_size * this._ratio)}px Arial, Tahoma, Verdana, sans-serif`;
		ctx.textBaseline = 'middle';

		for (const text of [`${state.label}  ${CStateTimeline.formatDuration(duration)}`, state.label]) {
			if (text !== '' && ctx.measureText(text).width + padding * 2 <= right - left) {
				ctx.fillStyle = CStateTimeline.getContrastColor(state.color);
				ctx.fillText(text, left + padding, y + height / 2 + 0.5 * this._ratio);

				return;
			}
		}
	}

	/**
	 * Pill shaped segments. State 1 is muted, state 0 stands out.
	 */
	_drawCapsules(ctx, spans, y, height) {
		const gap = Math.max(1, Math.round(this._ratio));

		for (const [start, end, kind] of spans) {
			const left = start + gap;
			const right = Math.max(left + 2 * gap, end - gap);

			CStateTimeline.roundRect(ctx, left, y + gap, right - left, height - 2 * gap, (height - 2 * gap) / 2);

			if (kind === CStateTimeline.KIND_NODATA) {
				ctx.fillStyle = this._nodata_pattern;
			}
			else {
				ctx.globalAlpha = kind === CStateTimeline.KIND_1 ? 0.38 : 1;
				ctx.fillStyle = this._kindColor(kind);
			}

			ctx.fill();
			ctx.globalAlpha = 1;
		}
	}

	/**
	 * Status page like ticks. A tick shows the worst state within it: state 0, missing data, state 1.
	 */
	_drawUptimeBars(ctx, spans, y, height) {
		const masks = this._masks;
		const {tick, count, step} = this._getUptimeTicks();
		const radius = 2 * this._ratio;

		for (let i = 0; i < count; i++) {
			const p0 = Math.floor(i * step);
			const p1 = Math.min(masks.length, Math.floor((i + 1) * step));
			let mask = 0;

			for (let p = p0; p < p1; p++) {
				mask |= masks[p];
			}

			if (mask === 0) {
				continue;
			}

			const kind = mask & CStateTimeline.KIND_0
				? CStateTimeline.KIND_0
				: (mask & CStateTimeline.KIND_NODATA ? CStateTimeline.KIND_NODATA : CStateTimeline.KIND_1);

			CStateTimeline.roundRect(ctx, p0, y, tick, height, radius);
			ctx.fillStyle = kind === CStateTimeline.KIND_NODATA ? this._nodata_pattern : this._kindColor(kind);
			ctx.fill();
		}
	}

	/**
	 * Vertical gradient, highlight on top, rounded row ends and dark separators at state changes.
	 */
	_drawGlossy(ctx, spans, y, height) {
		const gradients = {};

		CStateTimeline.roundRect(ctx, spans[0][0], y, spans[spans.length - 1][1] - spans[0][0], height,
			4 * this._ratio
		);
		ctx.clip();

		for (const [start, end, kind] of spans) {
			if (kind !== CStateTimeline.KIND_1 && kind !== CStateTimeline.KIND_0) {
				this._fillKind(ctx, kind, start, y, end - start, height);

				continue;
			}

			if (!(kind in gradients)) {
				const color = this._kindColor(kind);
				const gradient = ctx.createLinearGradient(0, y, 0, y + height);

				gradient.addColorStop(0, CStateTimeline.shadeColor(color, 0.28));
				gradient.addColorStop(0.5, color);
				gradient.addColorStop(1, CStateTimeline.shadeColor(color, -0.22));
				gradients[kind] = gradient;
			}

			ctx.fillStyle = gradients[kind];
			ctx.fillRect(start, y, end - start, height);
		}

		const line = Math.max(1, Math.round(this._ratio));

		ctx.fillStyle = 'rgba(255, 255, 255, .28)';
		ctx.fillRect(spans[0][0], y + line, spans[spans.length - 1][1] - spans[0][0], line);

		ctx.fillStyle = 'rgba(0, 0, 0, .55)';

		for (let i = 1; i < spans.length; i++) {
			if (spans[i][1] - spans[i][0] >= CStateTimeline.MARKER_MIN_SPAN * this._ratio
					&& spans[i - 1][1] - spans[i - 1][0] >= CStateTimeline.MARKER_MIN_SPAN * this._ratio) {
				ctx.fillRect(spans[i][0], y, line, height);
			}
		}
	}

	/**
	 * State 1 is a thin track, state 0 (and pixels with both states) are full height glowing blocks.
	 */
	_drawTrack(ctx, spans, y, height, scale) {
		const track_y = y + Math.round(height * 0.35);
		const track_height = Math.max(1, Math.round(height * 0.3));

		for (const [start, end, kind] of spans) {
			if (kind === CStateTimeline.KIND_1) {
				ctx.globalAlpha = 0.55;
				CStateTimeline.roundRect(ctx, start, track_y, end - start, track_height, track_height / 2);
				ctx.fillStyle = this._kindColor(kind);
				ctx.fill();
				ctx.globalAlpha = 1;
			}
			else if (kind === CStateTimeline.KIND_NODATA) {
				ctx.fillStyle = this._nodata_pattern;
				ctx.fillRect(start, track_y, end - start, track_height);
			}
		}

		ctx.shadowColor = this._kindColor(CStateTimeline.KIND_0);
		ctx.shadowBlur = 8 * this._ratio;
		ctx.fillStyle = this._kindColor(CStateTimeline.KIND_0);

		for (const [start, end, kind] of spans) {
			if (kind === CStateTimeline.KIND_0 || kind === CStateTimeline.KIND_MIXED) {
				ctx.globalAlpha = kind === CStateTimeline.KIND_MIXED ? 0.7 : 1;
				CStateTimeline.roundRect(ctx, start, y, Math.max(2 * this._ratio, end - start), height,
					3 * this._ratio
				);
				ctx.fill();
			}
		}

		// Labels of incidents only.
		if (this._data.segment_labels) {
			ctx.globalAlpha = 1;
			ctx.shadowBlur = 0;

			for (const [start, end, kind] of spans) {
				if (kind === CStateTimeline.KIND_0) {
					this._drawSegmentLabel(ctx, kind, start, end, y, height, (end - start) / scale);
				}
			}
		}
	}

	/**
	 * State 1 is solid, state 0 is hatched with a bright border: distinguishable without colors.
	 */
	_drawHatched(ctx, spans, y, height) {
		const line = Math.max(1, Math.round(this._ratio));
		const color_0 = this._kindColor(CStateTimeline.KIND_0);

		for (const [start, end, kind] of spans) {
			if (kind !== CStateTimeline.KIND_0) {
				this._fillKind(ctx, kind, start, y, end - start, height);

				continue;
			}

			// Hatched like missing data, but with a tint and stripes of the state 0 color.
			ctx.globalAlpha = 0.22;
			ctx.fillStyle = color_0;
			ctx.fillRect(start, y, end - start, height);
			ctx.globalAlpha = 1;
			ctx.fillStyle = this._hatch_pattern;
			ctx.fillRect(start, y, end - start, height);

			if (end - start > 2 * line) {
				ctx.strokeStyle = color_0;
				ctx.lineWidth = line;
				ctx.strokeRect(start + line / 2, y + line / 2, end - start - line, height - line);
			}
		}
	}

	/**
	 * Ticks of the "Uptime bars" style, in device pixels: tick width, number of ticks and distance between ticks.
	 */
	_getUptimeTicks() {
		const ratio = window.devicePixelRatio || 1;
		const width = this._canvas.width;
		const tick = Math.max(2, Math.round(CStateTimeline.UPTIME_TICK_WIDTH * ratio));
		const gap = Math.max(1, Math.round(CStateTimeline.UPTIME_TICK_GAP * ratio));
		const count = Math.max(1, Math.floor((width + gap) / (tick + gap)));

		return {tick, count, step: (width + gap) / count};
	}

	_kindColor(kind) {
		return this._data.states[kind === CStateTimeline.KIND_1 ? CStateTimeline.STATE_1 : CStateTimeline.STATE_0]
			.color;
	}

	/**
	 * Fill a span: state color, missing data pattern, or both state colors (upper half is state 1) for pixels with
	 * several state changes.
	 */
	_fillKind(ctx, kind, x, y, width, height) {
		switch (kind) {
			case CStateTimeline.KIND_1:
			case CStateTimeline.KIND_0:
				ctx.fillStyle = this._kindColor(kind);
				ctx.fillRect(x, y, width, height);
				break;

			case CStateTimeline.KIND_MIXED:
				const half = Math.round(height / 2);

				ctx.fillStyle = this._kindColor(CStateTimeline.KIND_1);
				ctx.fillRect(x, y, width, half);
				ctx.fillStyle = this._kindColor(CStateTimeline.KIND_0);
				ctx.fillRect(x, y + half, width, height - half);
				break;

			case CStateTimeline.KIND_NODATA:
				ctx.fillStyle = this._nodata_pattern;
				ctx.fillRect(x, y, width, height);
				break;
		}
	}

	_updateLabels(first, last, shift) {
		const {row_height, label_width} = this._layout;
		const count = label_width > 0 ? last - first + 1 : 0;

		while (this._label_pool.length < count) {
			const label = this._createElement('div', 'state-timeline-label');
			const link = this._createElement('a', 'link-action');

			link.tabIndex = 0;
			link.setAttribute('role', 'button');
			link.setAttribute('aria-haspopup', 'true');
			label.append(link);

			this._labels.append(label);
			this._label_pool.push(label);
		}

		this._label_pool.forEach((label, index) => {
			const row_index = first + index;

			if (index >= count) {
				label.hidden = true;

				return;
			}

			const row = this._data.rows[row_index];
			const link = label.firstChild;

			label.hidden = false;
			label.style.top = `${index * row_height - shift}px`;
			label.style.height = `${row_height}px`;
			label.style.lineHeight = `${row_height}px`;
			label.style.width = `${label_width}px`;
			label.style.fontSize = row_height < 13 ? `${Math.floor(row_height) - 1}px` : '';

			if (link.dataset.index !== String(row_index) || link.textContent !== row.label) {
				link.textContent = row.label;
				link.dataset.index = row_index;
				link.setAttribute('data-menu-popup', JSON.stringify(row.menu));
				jQuery(link).removeData('menu-popup');
			}
		});
	}

	_drawAxis() {
		const {plot_width, rows_height} = this._layout;
		const theme = this._data.theme;

		this._svg_axis.replaceChildren();

		this._svg_axis.append(this._createSvgElement('line', {
			x1: 0,
			x2: plot_width,
			y1: rows_height + 0.5,
			y2: rows_height + 0.5,
			stroke: theme.grid
		}));

		this._text_measure.font = getComputedStyle(this._svg).font;

		for (const [x, label] of this._grid) {
			const half_width = this._text_measure.measureText(label).width / 2;

			this._svg_axis.append(this._createSvgElement('line', {
				x1: Math.round(x) + 0.5,
				x2: Math.round(x) + 0.5,
				y1: rows_height,
				y2: rows_height + 4,
				stroke: theme.grid
			}));

			if (x - half_width < 0 || x + half_width > plot_width) {
				continue;
			}

			const text = this._createSvgElement('text', {
				x,
				y: rows_height + 15,
				fill: theme.text,
				'text-anchor': 'middle'
			});

			text.textContent = label;
			this._svg_axis.append(text);
		}
	}

	/**
	 * Get time grid of the X axis. Same algorithm and time formats as in the Graph widget (see
	 * CSvgGraph::getTimeGridWithPosition).
	 *
	 * @returns {Map<number, string>}  Labels by position.
	 */
	_getTimeGrid() {
		const {from_ts, to_ts} = this._data.time_period;
		const width = this._layout.plot_width;
		const period = to_ts - from_ts;
		const step = Math.round(period / width * 100);
		const formats = this._data.formats.axis;

		if (step === 0) {
			return new Map([
				[0, CStateTimeline.formatDate(this._data.formats.time, from_ts)],
				[width, CStateTimeline.formatDate(this._data.formats.time, to_ts)]
			]);
		}

		const start = from_ts + step - from_ts % step;
		let grid;

		for (const format of formats) {
			grid = new Map();

			for (let clock = start; to_ts >= clock; clock += step) {
				grid.set(Math.round(width - width * (to_ts - clock) / period), CStateTimeline.formatDate(format, clock));
			}

			if (format === formats[formats.length - 1] || new Set(grid.values()).size === grid.size) {
				break;
			}
		}

		return grid;
	}

	_makePatterns() {
		this._hatch_pattern = this._createHatchPattern(this._data.states[CStateTimeline.STATE_0].color, 1.5);

		if (this._data.nodata_color !== null) {
			this._nodata_pattern = this._data.nodata_color;

			return;
		}

		this._nodata_pattern = this._createHatchPattern(this._data.theme.grid, 1);
	}

	_createHatchPattern(color, line_width) {
		const size = Math.round(6 * (window.devicePixelRatio || 1));
		const canvas = document.createElement('canvas');

		canvas.width = size;
		canvas.height = size;

		const ctx = canvas.getContext('2d');

		ctx.strokeStyle = color;
		ctx.lineWidth = line_width * (window.devicePixelRatio || 1);
		ctx.beginPath();
		ctx.moveTo(0, size);
		ctx.lineTo(size, 0);
		ctx.moveTo(-size / 2, size / 2);
		ctx.lineTo(size / 2, -size / 2);
		ctx.moveTo(size / 2, size * 1.5);
		ctx.lineTo(size * 1.5, size / 2);
		ctx.stroke();

		return ctx.createPattern(canvas, 'repeat');
	}

	// Navigation.

	_updateNav() {
		const rows_count = this._data.rows.length;
		const {visible_rows} = this._layout;
		const first = Math.round(this._offset) + 1;

		this._nav_info.textContent = this._layout.scrollable
			? sprintf(t('Items %1$s-%2$s of %3$s'), first, first + visible_rows - 1, rows_count)
			: '';

		if (!this._layout.scrollable) {
			return;
		}

		const track_height = this._nav_track.clientHeight;
		const thumb_height = Math.max(20, track_height * visible_rows / rows_count);
		const max_offset = rows_count - visible_rows;

		this._nav_thumb.style.height = `${thumb_height}px`;
		this._nav_thumb.style.transform =
			`translateY(${max_offset > 0 ? (track_height - thumb_height) * this._offset / max_offset : 0}px)`;

		this._nav_track.setAttribute('aria-valuemin', 0);
		this._nav_track.setAttribute('aria-valuemax', max_offset);
		this._nav_track.setAttribute('aria-valuenow', Math.round(this._offset));
	}

	/**
	 * Scroll to the given row (the first visible one).
	 */
	_scrollTo(offset, animate = false) {
		const target = this._clampOffset(Math.round(offset));

		this._stopAnimation();

		if (!animate || target === this._offset) {
			this._setOffset(target);

			return;
		}

		const start = this._offset;
		const start_time = performance.now();

		this._animation_target = target;

		const step = (now) => {
			const progress = Math.min(1, (now - start_time) / CStateTimeline.SCROLL_ANIMATION_MS);
			const eased = 1 - Math.pow(1 - progress, 3);

			this._setOffset(start + (target - start) * eased);

			this._animation = progress < 1 ? requestAnimationFrame(step) : null;

			if (this._animation === null) {
				this._animation_target = null;
			}
		};

		this._animation = requestAnimationFrame(step);
	}

	_stopAnimation() {
		if (this._animation !== null) {
			cancelAnimationFrame(this._animation);
			this._animation = null;
		}

		this._animation_target = null;
	}

	_setOffset(offset) {
		offset = this._clampOffset(offset);

		if (offset !== this._offset) {
			this._offset = offset;
			this._scheduleDraw();
		}
	}

	_startThumbDrag(e) {
		if (e.button !== 0 || !this._isScrollable()) {
			return;
		}

		e.preventDefault();
		this._stopAnimation();
		this._nav_track.focus();

		const rows_count = this._data.rows.length;
		const max_offset = rows_count - this._layout.visible_rows;
		const track_rect = this._nav_track.getBoundingClientRect();
		const thumb_rect = this._nav_thumb.getBoundingClientRect();
		const free_height = Math.max(1, track_rect.height - thumb_rect.height);
		const offsetAt = (client_y) => (client_y - track_rect.top - grab) / free_height * max_offset;

		// Clicking the track outside the thumb moves the thumb center to the pointer.
		let grab = e.clientY - thumb_rect.top;

		if (e.target !== this._nav_thumb) {
			grab = thumb_rect.height / 2;
			this._setOffset(offsetAt(e.clientY));
		}

		this._nav_thumb.classList.add('is-dragging');
		this._nav_track.setPointerCapture(e.pointerId);

		const move = (e) => this._setOffset(offsetAt(e.clientY));

		const end = () => {
			this._nav_track.removeEventListener('pointermove', move);
			this._nav_track.removeEventListener('pointerup', end);
			this._nav_track.removeEventListener('pointercancel', end);
			this._nav_thumb.classList.remove('is-dragging');

			// Snap to a row.
			this._scrollTo(this._offset, true);
		};

		this._nav_track.addEventListener('pointermove', move);
		this._nav_track.addEventListener('pointerup', end);
		this._nav_track.addEventListener('pointercancel', end);
	}

	// Pointer: helper line, row highlight and tooltip.

	_getPointerPosition(client_x, client_y) {
		const rect = this._plot.getBoundingClientRect();

		return {x: client_x - rect.left, y: client_y - rect.top};
	}

	_updatePointer() {
		if (this._pointer === null || this._data === null || this._sbox !== null) {
			this._hidePointer();

			return;
		}

		const {plot_width, rows_height, row_height} = this._layout;
		const {x, y} = this._getPointerPosition(this._pointer.client_x, this._pointer.client_y);

		if (x < 0 || x > plot_width || y < 0 || y > rows_height + CStateTimeline.AXIS_HEIGHT) {
			this._hidePointer();

			return;
		}

		this._svg_helper.setAttribute('x1', x);
		this._svg_helper.setAttribute('x2', x);
		this._svg_helper.setAttribute('y2', rows_height);

		const index = y < rows_height ? Math.floor(this._offset + y / row_height) : -1;

		if (index < 0 || index >= this._data.rows.length) {
			this._svg_row.setAttribute('width', 0);
			this._svg_segment.setAttribute('d', '');
			this._hideHint();

			return;
		}

		this._svg_row.setAttribute('x', 0.5);
		this._svg_row.setAttribute('y', (index - this._offset) * row_height + 0.5);
		this._svg_row.setAttribute('width', plot_width - 1);
		this._svg_row.setAttribute('height', row_height - 1);

		const {from_ts, to_ts} = this._data.time_period;
		const time = from_ts + (to_ts - from_ts) * x / plot_width;

		this._updateSegmentHighlight(index, time);

		if (this._data.show_tooltip) {
			this._showHint(this._pointer.event, this._plot, this._makeHintContent(this._data.rows[index], time));
		}
	}

	_hidePointer() {
		this._svg_helper.setAttribute('x1', -10);
		this._svg_helper.setAttribute('x2', -10);
		this._svg_row.setAttribute('width', 0);
		this._svg_segment.setAttribute('d', '');
		this._hideHint();
	}

	/**
	 * Highlight the state interval under the pointer (same interval as "Since" - "Until" in the tooltip) with a glow.
	 * The shape of the highlight follows the shape of segments of the current style.
	 */
	_updateSegmentHighlight(index, time) {
		const row = this._data.rows[index];
		const timeline = row.timeline;
		const run = timeline !== null && time <= timeline.end ? CStateTimeline.findRun(timeline.t, time) : -1;

		if (run < 0) {
			this._svg_segment.setAttribute('d', '');

			return;
		}

		const {plot_width, row_height, row_gap} = this._layout;
		const {from_ts, to_ts} = this._data.time_period;
		const [first, next] = this._getStateBounds(row, run);
		const toX = (ts) => Math.min(plot_width, Math.max(0, (ts - from_ts) * plot_width / (to_ts - from_ts)));

		let x0 = toX(timeline.t[first]);
		let x1 = toX(next < timeline.t.length ? timeline.t[next] : timeline.end);

		const at_row_start = x0 <= toX(timeline.t[0]) + 0.5;
		const at_row_end = x1 >= toX(timeline.end) - 0.5;

		// Short intervals are highlighted at least 4 pixels wide.
		if (x1 - x0 < 4) {
			const center = (x0 + x1) / 2;

			x0 = Math.max(0, center - 2);
			x1 = Math.min(plot_width, center + 2);
		}

		const state = timeline.s[run];
		let y = (index - this._offset) * row_height + row_gap / 2;
		let height = row_height - row_gap;
		let radius_left = 0;
		let radius_right = 0;

		switch (this._data.style) {
			case CStateTimeline.STYLE_SEGMENTS:
				// Segments are separated by a 1 pixel gap.
				x0 += at_row_start ? 0 : 1;
				x1 -= at_row_end ? 0 : 1;
				radius_left = radius_right = 3;
				break;

			case CStateTimeline.STYLE_CAPSULES:
				x0 += 1;
				x1 -= 1;
				y += 1;
				height -= 2;
				radius_left = radius_right = height / 2;
				break;

			case CStateTimeline.STYLE_UPTIME_BARS: {
				// Whole ticks overlapping the interval are highlighted.
				const {tick, count, step} = this._getUptimeTicks();
				const ratio = this._canvas.width / plot_width;
				const first_tick = Math.min(count - 1, Math.floor(x0 * ratio / step));
				const last_tick = Math.max(first_tick, Math.min(count - 1, Math.ceil(x1 * ratio / step) - 1));

				x0 = Math.floor(first_tick * step) / ratio;
				x1 = (Math.floor(last_tick * step) + tick) / ratio;
				radius_left = radius_right = 2;
				break;
			}

			case CStateTimeline.STYLE_GLOSSY:
				// Only the row ends are rounded.
				radius_left = at_row_start ? 4 : 0;
				radius_right = at_row_end ? 4 : 0;
				break;

			case CStateTimeline.STYLE_TRACK:
				if (state === CStateTimeline.STATE_0 || state === CStateTimeline.STATE_MIXED) {
					radius_left = radius_right = 3;
				}
				else {
					// State 1 and missing data are a thin track.
					y += Math.round(height * 0.35);
					height = Math.max(1, Math.round(height * 0.3));
					radius_left = radius_right = height / 2;
				}
				break;
		}

		const color = state === CStateTimeline.STATE_NODATA
			? this._data.theme.grid
			: this._data.states[state === CStateTimeline.STATE_1 ? CStateTimeline.STATE_1 : CStateTimeline.STATE_0]
				.color;
		const glow = CStateTimeline.shadeColor(color, 0.35);

		this._svg_segment.setAttribute('d',
			CStateTimeline.makeRoundedRectPath(x0, y, Math.max(1, x1 - x0), Math.max(1, height), radius_left,
				radius_right
			)
		);
		this._svg_segment.setAttribute('stroke', glow);
		this._svg_segment.style.filter = `drop-shadow(0 0 2px ${color}) drop-shadow(0 0 4px ${color})`;
	}

	_showHint(e, target, content) {
		if (this._hintbox !== null && this._hintbox_target !== target) {
			this._hideHint();
		}

		if (this._hintbox === null) {
			this._hintbox = hintBox.createBox(e, target, content, '', false, false, '.wrapper', false);
			this._hintbox_target = target;
		}
		else {
			// The content element is replaced directly: the hintbox markup differs between Zabbix 7.0 releases.
			this._hint_content.replaceWith(content);
		}

		this._hint_content = content;

		hintBox.positionElement(e, target, this._hintbox);
	}

	_hideHint() {
		if (this._hintbox !== null) {
			this._hintbox.remove();
			this._hintbox_target.removeAttribute('aria-describedby');
			this._hintbox = null;
			this._hintbox_target = null;
			this._hint_content = null;
		}
	}

	/**
	 * Tooltip of the item row at the given time: only the hovered item is shown.
	 */
	_makeHintContent(row, time) {
		const content = document.createElement('div');

		content.classList.add('svg-graph-hintbox', 'state-timeline-hintbox');
		content.append(this._createElement('div', 'header', row.label));

		const table = this._createElement('table', 'state-timeline-hintbox-table');
		const add = (label, value, css_class = null) => {
			const tr = document.createElement('tr');
			const td_value = this._createElement('td', css_class);

			td_value.append(value);
			tr.append(this._createElement('td', 'grey', label), td_value);
			table.append(tr);
		};

		const timeline = row.timeline;
		const {from_ts, to_ts} = this._data.time_period;
		const long_period = to_ts - from_ts >= 86400;
		const format = long_period ? this._data.formats.date_time : this._data.formats.time;
		const fmt = (ts) => CStateTimeline.formatDate(format, ts);

		add(t('Time'), CStateTimeline.formatDate(this._data.formats.date_time, time));

		const index = timeline !== null && time <= timeline.end ? CStateTimeline.findRun(timeline.t, time) : -1;
		const state = index >= 0 ? timeline.s[index] : CStateTimeline.STATE_NODATA;

		if (state === CStateTimeline.STATE_NODATA) {
			add(t('State'), t('No data'));
			content.append(table);

			return content;
		}

		const state_label = document.createDocumentFragment();

		if (state === CStateTimeline.STATE_MIXED) {
			state_label.append(this._makeColorBox(CStateTimeline.STATE_1), this._makeColorBox(CStateTimeline.STATE_0),
				t('Several changes')
			);
		}
		else {
			state_label.append(this._makeColorBox(state), this._data.states[state].label);
		}

		add(t('State'), state_label);

		const [raw, formatted] = timeline.values[timeline.v[index]];

		add(t('Value'), formatted !== '' ? formatted : raw);

		const [first, next] = this._getStateBounds(row, index);
		const approx = timeline.exact ? '' : '≈ ';
		const since_unknown = first === 0 && timeline.before;
		const since = timeline.t[first];

		add(t('Since'), since_unknown ? sprintf(t('before %1$s'), fmt(from_ts)) : approx + fmt(since));

		if (this._data.show_duration) {
			const is_last = next === timeline.t.length;
			const until = is_last ? timeline.end : timeline.t[next];

			// The last state lasts until now, or at least until the end of a period in the past.
			const is_ongoing = is_last && timeline.end >= this._data.time_period.now_ts;
			const until_unknown = is_last && !is_ongoing;

			add(t('Until'), is_ongoing ? t('now') : (until_unknown ? '≥ ' : approx) + fmt(until));

			const duration = Math.max(0, Math.round(until - since));

			add(t('Duration'), (since_unknown || until_unknown ? '≥ ' : approx)
				+ (duration > 0 ? formatTimestamp(duration, false, true) : '< 1s')
			);
		}

		content.append(table);

		if (!timeline.exact) {
			content.append(this._createElement('div', 'state-timeline-hintbox-note grey',
				t('Approximate: aggregated data, zoom in for exact time.')
			));
		}

		return content;
	}

	/**
	 * Get the first run of the state and the first run after the state, for the given run. Adjacent runs with the
	 * same state and different values belong to the same state.
	 */
	_getStateBounds(row, index) {
		const timeline = row.timeline;

		if (row.state_bounds === null) {
			const count = timeline.s.length;
			const first = new Int32Array(count);
			const next = new Int32Array(count);

			for (let i = 0; i < count; i++) {
				first[i] = i > 0 && timeline.s[i] === timeline.s[i - 1] ? first[i - 1] : i;
			}

			for (let i = count - 1; i >= 0; i--) {
				next[i] = i < count - 1 && timeline.s[i] === timeline.s[i + 1] ? next[i + 1] : i + 1;
			}

			row.state_bounds = {first, next};
		}

		return [row.state_bounds.first[index], row.state_bounds.next[index]];
	}

	_makeColorBox(state) {
		const box = this._createElement('span', 'svg-graph-hintbox-item-color');

		box.style.backgroundColor = this._data.states[state].color;

		return box;
	}

	// Selection box (zoom), same behavior as in the Graph widget.

	_startSBox(e) {
		if (e.button !== 0 || this._data === null || !this._data.sbox) {
			return;
		}

		const {x, y} = this._getPointerPosition(e.clientX, e.clientY);

		if (x < 0 || x > this._layout.plot_width || y < 0 || y > this._layout.rows_height) {
			return;
		}

		e.preventDefault();

		this._sbox = {start: x, end: x, boxing: false};

		const move = (e) => this._moveSBox(e);
		const end = (e) => this._endSBox(e);
		const key = (e) => {
			if (e.key === 'Escape') {
				this._destroySBox();
			}
		};

		document.addEventListener('mousemove', move);
		document.addEventListener('mouseup', end);
		document.addEventListener('keydown', key);

		this._sbox.listeners = [['mousemove', move], ['mouseup', end], ['keydown', key]];
	}

	_moveSBox(e) {
		const {plot_width, rows_height} = this._layout;
		const {x} = this._getPointerPosition(e.clientX, e.clientY);

		this._sbox.end = Math.min(Math.max(0, x), plot_width);

		if (!this._sbox.boxing && Math.abs(this._sbox.end - this._sbox.start) >= 1) {
			this._sbox.boxing = true;
			this._hidePointer();
			this._callbacks.onInteractionStart();
		}

		if (!this._sbox.boxing) {
			return;
		}

		const left = Math.min(this._sbox.start, this._sbox.end);
		const box_width = Math.abs(this._sbox.end - this._sbox.start);
		const {from_ts, to_ts} = this._data.time_period;
		const seconds = Math.round(box_width * (to_ts - from_ts) / plot_width);

		this._svg_selection.setAttribute('x', left);
		this._svg_selection.setAttribute('y', 0);
		this._svg_selection.setAttribute('width', box_width);
		this._svg_selection.setAttribute('height', rows_height);

		this._svg_selection_text.setAttribute('x', left + 5);
		this._svg_selection_text.setAttribute('y', 15);
		this._svg_selection_text.textContent = formatTimestamp(seconds, false, true)
			+ (seconds < this._data.min_period ? ` [min 1${t('S_MINUTE_SHORT')}]` : '');
	}

	_endSBox(e) {
		const sbox = this._sbox;

		this._destroySBox();

		if (sbox === null || !sbox.boxing) {
			return;
		}

		const {plot_width} = this._layout;
		const {from_ts, to_ts} = this._data.time_period;
		const spp = (to_ts - from_ts) / plot_width;
		const {x} = this._getPointerPosition(e.clientX, e.clientY);
		const end = Math.min(Math.max(0, x), plot_width);

		const seconds = Math.round(Math.abs(end - sbox.start) * spp);
		const from_offset = Math.floor(Math.min(sbox.start, end)) * spp;
		const to_offset = Math.floor(plot_width - Math.max(sbox.start, end)) * spp;

		if (seconds > this._data.min_period && (from_offset > 0 || to_offset > 0)) {
			this._callbacks.onZoom({
				from_offset: Math.max(0, Math.ceil(from_offset)),
				to_offset: Math.ceil(to_offset)
			});
		}
	}

	_destroySBox() {
		if (this._sbox === null) {
			return;
		}

		for (const [type, listener] of this._sbox.listeners) {
			document.removeEventListener(type, listener);
		}

		const was_boxing = this._sbox.boxing;

		this._sbox = null;

		this._svg_selection.setAttribute('width', 0);
		this._svg_selection.setAttribute('height', 0);
		this._svg_selection_text.textContent = '';

		if (was_boxing) {
			this._callbacks.onInteractionEnd();
		}
	}

	// Helpers.

	_getNoticeText() {
		const {total, search_limit, rows} = this._data;

		if (total <= rows.length) {
			return '';
		}

		let text = sprintf(t('%1$s items match the patterns, the first %2$s are displayed.'), total, rows.length);

		if (search_limit) {
			text += ' ' + t('Too many items to sort all of them, specify more precise patterns.');
		}

		return text;
	}

	_createElement(tag, css_class = null, text = null) {
		const element = document.createElement(tag);

		if (css_class !== null) {
			element.className = css_class;
		}

		if (text !== null) {
			element.textContent = text;
		}

		return element;
	}

	_createSvgElement(tag, attributes = {}) {
		const element = document.createElementNS('http://www.w3.org/2000/svg', tag);

		for (const [name, value] of Object.entries(attributes)) {
			element.setAttribute(name, value);
		}

		return element;
	}

	/**
	 * SVG path of a rectangle with separate radii of the left and the right corners.
	 */
	static makeRoundedRectPath(x, y, width, height, radius_left, radius_right) {
		const rl = Math.max(0, Math.min(radius_left, height / 2, width / 2));
		const rr = Math.max(0, Math.min(radius_right, height / 2, width / 2));

		return `M${x + rl},${y}H${x + width - rr}`
			+ (rr > 0 ? `A${rr},${rr} 0 0 1 ${x + width},${y + rr}` : '')
			+ `V${y + height - rr}`
			+ (rr > 0 ? `A${rr},${rr} 0 0 1 ${x + width - rr},${y + height}` : '')
			+ `H${x + rl}`
			+ (rl > 0 ? `A${rl},${rl} 0 0 1 ${x},${y + height - rl}` : '')
			+ `V${y + rl}`
			+ (rl > 0 ? `A${rl},${rl} 0 0 1 ${x + rl},${y}` : '')
			+ 'Z';
	}

	static isState(kind) {
		return kind === CStateTimeline.KIND_1 || kind === CStateTimeline.KIND_0;
	}

	static roundRect(ctx, x, y, width, height, radius) {
		radius = Math.max(0, Math.min(radius, width / 2, height / 2));

		ctx.beginPath();
		ctx.moveTo(x + radius, y);
		ctx.arcTo(x + width, y, x + width, y + height, radius);
		ctx.arcTo(x + width, y + height, x, y + height, radius);
		ctx.arcTo(x, y + height, x, y, radius);
		ctx.arcTo(x, y, x + width, y, radius);
		ctx.closePath();
	}

	/**
	 * Lighten (factor > 0) or darken (factor < 0) a "#RRGGBB" color.
	 */
	static shadeColor(color, factor) {
		const value = parseInt(color.slice(1), 16);

		return 'rgb(' + [value >> 16, (value >> 8) & 255, value & 255]
			.map((c) => Math.round(factor > 0 ? c + (255 - c) * factor : c * (1 + factor)))
			.join(',') + ')';
	}

	/**
	 * Text color readable on the given "#RRGGBB" background.
	 */
	static getContrastColor(color) {
		const value = parseInt(color.slice(1), 16);
		const luminance = (0.299 * (value >> 16) + 0.587 * ((value >> 8) & 255) + 0.114 * (value & 255)) / 255;

		return luminance > 0.6 ? 'rgba(0, 0, 0, .75)' : '#ffffff';
	}

	/**
	 * Short duration for segment labels: 45s, 12m, 3h 20m, 2d 5h.
	 */
	static formatDuration(seconds) {
		seconds = Math.round(seconds);

		if (seconds < 60) {
			return `${seconds}${t('S_SECOND_SHORT')}`;
		}

		const minutes = Math.round(seconds / 60);

		if (minutes < 60) {
			return `${minutes}${t('S_MINUTE_SHORT')}`;
		}

		const hours = Math.floor(minutes / 60);

		if (hours < 24) {
			return `${hours}${t('S_HOUR_SHORT')}` + (minutes % 60 ? ` ${minutes % 60}${t('S_MINUTE_SHORT')}` : '');
		}

		return `${Math.floor(hours / 24)}${t('S_DAY_SHORT')}` + (hours % 24 ? ` ${hours % 24}${t('S_HOUR_SHORT')}` : '');
	}

	/**
	 * Find the run containing the given time (binary search).
	 *
	 * @param {number[]} starts  Run start times, ascending.
	 * @param {number}   time
	 *
	 * @returns {number}  Run index, -1 if the time is before the first run.
	 */
	static findRun(starts, time) {
		let low = 0;
		let high = starts.length - 1;
		let found = -1;

		while (low <= high) {
			const middle = (low + high) >> 1;

			if (starts[middle] <= time) {
				found = middle;
				low = middle + 1;
			}
			else {
				high = middle - 1;
			}
		}

		return found;
	}

	/**
	 * Format timestamp in the user time zone, using a PHP date() compatible format (supported characters: d, j, m, n,
	 * M, Y, y, H, G, h, g, i, s, A, a).
	 */
	static formatDate(format, timestamp) {
		const date = new CDate(timestamp * 1000);
		const pad = (value) => String(value).padStart(2, '0');
		const hours = date.getHours();
		const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

		const tokens = {
			d: () => pad(date.getDate()),
			j: () => date.getDate(),
			m: () => pad(date.getMonth() + 1),
			n: () => date.getMonth() + 1,
			M: () => t(months[date.getMonth()]),
			Y: () => date.getFullYear(),
			y: () => pad(date.getFullYear() % 100),
			H: () => pad(hours),
			G: () => hours,
			h: () => pad((hours + 11) % 12 + 1),
			g: () => (hours + 11) % 12 + 1,
			i: () => pad(date.getMinutes()),
			s: () => pad(date.getSeconds()),
			A: () => hours < 12 ? 'AM' : 'PM',
			a: () => hours < 12 ? 'am' : 'pm'
		};

		let result = '';

		for (let i = 0; i < format.length; i++) {
			const char = format[i];

			if (char === '\\' && i + 1 < format.length) {
				result += format[++i];
			}
			else {
				result += char in tokens ? tokens[char]() : char;
			}
		}

		return result;
	}
}
