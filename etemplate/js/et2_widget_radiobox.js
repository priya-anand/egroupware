/**
 * EGroupware eTemplate2 - JS Radiobox object
 *
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @package etemplate
 * @subpackage api
 * @link http://www.egroupware.org
 * @author Nathan Gray
 * @copyright Nathan Gray 2011
 * @version $Id$
 */

"use strict";

/*egw:uses
	jquery.jquery;
	et2_core_inputWidget;
*/

/**
 * Class which implements the "radiobox" XET-Tag
 * 
 * @augments et2_inputWidget
 */ 
var et2_radiobox = et2_inputWidget.extend(
{
	attributes: {
		"set_value": {
			"name": "Set value",
			"type": "string",
			"default": "true",
			"description": "Value when selected"
		},
		"ro_true": {
			"name": "Read only selected",
			"type": "string",
			"default": "x",
			"description": "What should be displayed when readonly and selected"
		},
		"ro_false": {
			"name": "Read only unselected",
			"type": "string",
			"default": "",
			"description": "What should be displayed when readonly and not selected"
		}
	},
	
	legacyOptions: ["set_value", "ro_true", "ro_false"],

	/**
	 * Constructor
	 * 
	 * @memberOf et2_radiobox
	 */
	init: function() {
		this._super.apply(this, arguments);

		this.input = null;
		this.id = "";

		this.createInputWidget();

	},

	createInputWidget: function() {
		this.input = $j(document.createElement("input"))
			.val(this.options.set_value)
			.attr("type", "radio");

		this.input.addClass("et2_radiobox");

		this.setDOMNode(this.input[0]);
	},

	set_name: function(_name) {
		if(_name.substr(_name.length-2) != "[]")
		{
			_name += "[]";
		}
		this.input.attr("name", _name);
	},

	/**
	 * Default for radio buttons is label after button
	 *
	 * @param _label String New label for radio button.  Use %s to locate the radio button somewhere else in the label
	 */
	set_label: function(_label) {
		if(_label.length > 0 && _label.indexOf('%s')==-1)
		{
			_label = '%s'+_label;
		}
		this._super.apply(this, [_label]);
	},

	/**
	 * Override default to match against set/unset value
	 */
	set_value: function(_value) {
		if(_value == this.options.set_value) {
			this.input.attr("checked", "checked");
		} else {
			this.input.removeAttr("checked");
		}
	},

	/**
	 * Override default to return unchecked value
	 */
	getValue: function() {
		if(jQuery("input:checked", this._parent.getDOMNode()).val() == this.options.set_value) {
			return this.options.set_value;
		}
		return null;
	}
});
et2_register_widget(et2_radiobox, ["radio"]);

/**
 * @augments et2_valueWidget
 */
var et2_radiobox_ro = et2_valueWidget.extend([et2_IDetachedDOM], 
{
	attributes: {
		"set_value": {
			"name": "Set value",
			"type": "string",
			"default": "true",
			"description": "Value when selected"
		},
		"ro_true": {
			"name": "Read only selected",
			"type": "string",
			"default": "x",
			"description": "What should be displayed when readonly and selected"
		},
		"ro_false": {
			"name": "Read only unselected",
			"type": "string",
			"default": "",
			"description": "What should be displayed when readonly and not selected"
		},
		"label": {
			"name": "Label",
			"default": "",
			"type": "string"
		}
	},

	/**
	 * Constructor
	 * 
	 * @memberOf et2_radiobox_ro
	 */
	init: function() {
		this._super.apply(this, arguments);

		this.value = "";
		this.span = $j(document.createElement("span"))
			.addClass("et2_radiobox");

		this.setDOMNode(this.span[0]);
	},

	/**
	 * Override default to match against set/unset value
	 */
	set_value: function(_value) {
		if(_value == this.options.set_value) {
			this.span.text(this.options.ro_true);
		} else {
			this.span.text(this.options.ro_false);
		}
	},

	/**
	 * Code for implementing et2_IDetachedDOM
	 */
	getDetachedAttributes: function(_attrs)
	{
		// Show label in nextmatch instead of just x
		this.options.ro_true = this.options.label;
		_attrs.push("value");
	},

	getDetachedNodes: function()
	{
		return [this.span[0]];
	},

	setDetachedAttributes: function(_nodes, _values)
	{
		this.span = jQuery(_nodes[0]);
		this.set_value(_values["value"]);
	}
});
et2_register_widget(et2_radiobox_ro, ["radio_ro"]);


/**
 * A group of radio buttons
 * 
 * @augments et2_valueWidget
 */ 
var et2_radioGroup = et2_valueWidget.extend([et2_IDetachedDOM], 
{
	attributes: {
		"label": {
			"name": "Label",
			"default": "",
			"type": "string",
			"description": "The label is displayed above the list of radio buttons. The label can contain variables, as descript for name. If the label starts with a '@' it is replaced by the value of the content-array at this index (with the '@'-removed and after expanding the variables).",
			"translate": true
		},
		"value": {
			"name": "Value",
			"type": "string",
			"default": "true",
			"description": "Value for each radio button"
		},
		"ro_true": {
			"name": "Read only selected",
			"type": "string",
			"default": "x",
			"description": "What should be displayed when readonly and selected"
		},
		"ro_false": {
			"name": "Read only unselected",
			"type": "string",
			"default": "",
			"description": "What should be displayed when readonly and not selected"
		},
		"options": {
			"name": "Radio options",
			"type": "any",
			"default": {},
			"description": "Options for radio buttons.  Should be {value: label, ...}"
		},
		"required": {
			"name":	"Required",
			"default": false,
			"type": "boolean",
			"description": "If required, the user must select one of the options before the form can be submitted"
		}
	},

	createNamespace: false,

	/**
	 * Constructor
	 * 
	 * @param parent
	 * @param attrs
	 * @memberOf et2_radioGroup
	 */
	init: function(parent, attrs) {
		this._super.apply(this, arguments);
		this.node = $j(document.createElement("div"))
			.addClass("et2_vbox")
			.addClass("et2_box_widget");
		if(this.options.required)
		{
			// This isn't strictly allowed, but it works
			this.node.attr("required","required");
		}
		this.setDOMNode(this.node[0]);

		// The supported widget classes array defines a whitelist for all widget
		// classes or interfaces child widgets have to support.
		this.supportedWidgetClasses = [et2_radiobox,et2_radiobox_ro];
	},

	set_value: function(_value) {
		this.value = _value;
		for (var i = 0; i < this._children.length; i++)
		{
			var radio = this._children[i];
			radio.set_value(_value);
		}
	},

	getValue: function() {
		return jQuery("input:checked", this.getDOMNode()).val();
	},

	/**
	 * Set a bunch of radio buttons
	 * Options should be {value: label, ...}
	 */
	set_options: function(_options) {
		for(var key in _options)
		{
			var attrs = {
				// Add index so radios work properly
				"id": (this.options.readonly ? this.id : this.id + "[" +  "]"),
				set_value: key,
				label: _options[key],
				ro_true: this.options.ro_true,
				ro_false: this.options.ro_false,
				readonly: this.options.readonly,
				required: this.options.required
			};
			var radio = et2_createWidget("radio", attrs, this);
		}
		this.set_value(this.value);
	},

	/**
	 * Set a label on the group of radio buttons
	 */
	set_label: function(_value) {
		// Abort if ther was no change in the label
		if (_value == this.label)
		{
			return;
		}

		if (_value)
		{
			// Create the label container if it didn't exist yet
			if (this._labelContainer == null)
			{
				this._labelContainer = $j(document.createElement("label"))
				this.getSurroundings().insertDOMNode(this._labelContainer[0]);
			}

			// Clear the label container.
			this._labelContainer.empty();

			// Create the placeholder element and set it
			var ph = document.createElement("span");
			this.getSurroundings().setWidgetPlaceholder(ph);

			this._labelContainer
				.append(document.createTextNode(_value))
				.append(ph);
		}
		else
		{
			// Delete the labelContainer from the surroundings object
			if (this._labelContainer)
			{
				this.getSurroundings().removeDOMNode(this._labelContainer[0]);
			}
			this._labelContainer = null;
		}
	},
		
	/**
	 * Code for implementing et2_IDetachedDOM
	 * This doesn't need to be implemented.
	 * Individual widgets are detected and handled by the grid, but the interface is needed for this to happen
	 */
	getDetachedAttributes: function(_attrs)
	{
	},

	getDetachedNodes: function()
	{
		return [this.getDOMNode()];
	},

	setDetachedAttributes: function(_nodes, _values)
	{
	}

});
// No such tag as 'radiogroup', but it needs something
et2_register_widget(et2_radioGroup, ["radiogroup"]);
