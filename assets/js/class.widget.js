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
 * State timeline widget.
 *
 * Time period handling (broadcasting, feedback, zoom by selection box and zoom out by double click) is the same as in
 * the Graph widget (CWidgetSvgGraph, class.csvggraph.js).
 */
class CWidgetStateTimeline extends CWidget {

	onInitialize() {
		this._timeline = null;

		// Timeline width, for which the data was loaded.
		this._data_width = null;
	}

	onResize() {
		if (this._timeline === null) {
			return;
		}

		this._timeline.resize();

		// Aggregated data depends on the width: reload it if the timeline became much wider.
		const width = this._timeline.getTimelineWidth();

		if (this._state === WIDGET_STATE_ACTIVE && this._data_width !== null && width > this._data_width * 1.25) {
			this._startUpdating();
		}
	}

	onFeedback({type, value}) {
		if (type === CWidgetsData.DATA_TYPE_TIME_PERIOD) {
			this._startUpdating();

			this.feedback({time_period: value});

			return true;
		}

		return false;
	}

	promiseUpdate() {
		const time_period = this.getFieldsData().time_period;

		if (!this.hasBroadcast(CWidgetsData.DATA_TYPE_TIME_PERIOD) || this.isFieldsReferredDataUpdated('time_period')) {
			this.broadcast({
				[CWidgetsData.DATA_TYPE_TIME_PERIOD]: time_period
			});
		}

		return super.promiseUpdate();
	}

	getUpdateRequestData() {
		const request_data = super.getUpdateRequestData();

		if (!this.getFieldsReferredData().has('time_period')) {
			request_data.has_custom_time_period = 1;
		}

		const width = this._timeline !== null ? this._timeline.getTimelineWidth() : null;

		// Before the first draw the width of item labels is unknown: the whole width is requested.
		this._data_width = width ?? this._contents_size.width;
		request_data.timeline_width = Math.max(1, this._data_width);

		return request_data;
	}

	processUpdateResponse(response) {
		super.processUpdateResponse(response);

		if (response.timeline === undefined || response.timeline.rows.length === 0) {
			// Messages of the response are kept.
			this.onClearContents();
			this._body.innerHTML = '';
		}

		if (response.timeline === undefined) {
			return;
		}

		if (response.timeline.rows.length === 0) {
			this.setCoverMessage({
				message: t('No data found'),
				icon: ZBX_ICON_SEARCH_LARGE
			});

			return;
		}

		if (this._timeline === null) {
			this._body.innerHTML = '';

			this._timeline = new CStateTimeline(this._body, {
				onZoom: ({from_offset, to_offset}) => this._updateTimePeriod({
					method: 'rangeoffset',
					from_offset,
					to_offset
				}),
				onZoomOut: () => this._updateTimePeriod({method: 'zoomout'}),
				onInteractionStart: () => this._pauseUpdating(),
				onInteractionEnd: () => this._resumeUpdating()
			});
		}

		this._timeline.setData(response.timeline);
	}

	/**
	 * Contents are managed by the timeline, the view does not contain HTML.
	 */
	setContents(response) {
	}

	onClearContents() {
		if (this._timeline !== null) {
			this._timeline.destroy();
			this._timeline = null;
		}
	}

	onDeactivate() {
		if (this._timeline !== null) {
			this._timeline.resetInteraction();
		}
	}

	/**
	 * Change the time period, same as the Graph widget does on zooming.
	 */
	_updateTimePeriod(data) {
		const time_period = this.getFieldsData().time_period;

		this._schedulePreloader();

		const curl = new Curl('zabbix.php');

		curl.setArgument('action', 'timeselector.calc');

		fetch(curl.getUrl(), {
			method: 'POST',
			headers: {'Content-Type': 'application/json'},
			body: JSON.stringify({from: time_period.from, to: time_period.to, ...data})
		})
			.then((response) => response.json())
			.then((time_period) => {
				if ('error' in time_period) {
					throw {error: time_period.error};
				}

				if ('has_fields_errors' in time_period) {
					throw new Error();
				}

				this._startUpdating();
				this.feedback({time_period});
				this.broadcast({
					[CWidgetsData.DATA_TYPE_TIME_PERIOD]: time_period
				});
			})
			.catch((exception) => {
				let title;
				let messages = [];

				if (typeof exception === 'object' && 'error' in exception) {
					title = exception.error.title;
					messages = exception.error.messages;
				}
				else {
					title = t('Unexpected server error.');
				}

				this._updateMessages(messages, title);
			})
			.finally(() => {
				this._hidePreloader();
			});
	}

	hasPadding() {
		return true;
	}
}
