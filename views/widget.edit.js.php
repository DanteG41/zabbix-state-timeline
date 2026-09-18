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


use Modules\StateTimeline\Includes\WidgetForm;

?>

window.widget_statetimeline_form = new class {

	init() {
		this._form = document.getElementById('widget-dialogue-form');

		for (const colorpicker of this._form.querySelectorAll('.<?= ZBX_STYLE_COLOR_PICKER ?> input')) {
			$(colorpicker).colorpicker({
				appendTo: '.overlay-dialogue-body',
				use_default: true,
				onUpdate: window.setIndicatorColor
			});
		}

		this._style = document.getElementById('style');
		this._style.addEventListener('change', () => this._updateForm());

		this._updateForm();
	}

	_updateForm() {
		// State labels are available for the "Segments" and "Track and incidents" (incidents only) styles.
		document.getElementById('segment_labels').disabled = ![
			'<?= WidgetForm::STYLE_SEGMENTS ?>', '<?= WidgetForm::STYLE_TRACK ?>'
		].includes(this._style.value);
	}
};
