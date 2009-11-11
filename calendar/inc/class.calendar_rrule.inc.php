<?php
/**
 * eGroupWare - Calendar recurance rules
 *
 * @link http://www.egroupware.org
 * @package calendar
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @copyright (c) 2009 by RalfBecker-At-outdoor-training.de
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$
 */

/**
 * Recurance rule iterator
 *
 * The constructor accepts times only as DateTime (or decendents like egw_date) to work timezone-correct.
 * The timezone of the event is determined by timezone of the startime, other times get converted to that timezone.
 *
 * There's a static factory method calendar_rrule::event2rrule(array $event,$usertime=true), which converts an
 * event read by calendar_bo::read() or calendar_bo::search() to a rrule iterator.
 *
 * The rrule iterator object can be casted to string, to get a human readable describtion of the rrule.
 *
 * There's an interactive test-form, if the class get's called directly: http://localhost/egroupware/calendar/inc/class.calendar_rrule.inc.php
 *
 * @todo Integrate iCal import and export, so all recurence code resides just in this class
 * @todo Implement COUNT, can be stored in enddate assuming counts are far smaller then timestamps (eg. < 1000 is a count)
 * @todo Implement WKST (week start day), currently WKST=SU is used (this is not stored in current DB schema, it's a user preference)
 */
class calendar_rrule implements Iterator
{
	/**
	 * No recurrence
	 */
	const NONE = 0;
	/**
	 * Daily recurrence
	 */
	const DAILY = 1;
	/**
	 * Weekly recurrance on day(s) specified by bitfield in $data
	 */
	const WEEKLY = 2;
	/**
	 * Monthly recurrance iCal: monthly_bymonthday
	 */
	const MONTHLY_MDAY = 3;
	/**
	 * Monthly recurrance iCal: BYDAY (by weekday, eg. 1st Friday of month)
	 */
	const MONTHLY_WDAY = 4;
	/**
	 * Yearly recurrance
	 */
	const YEARLY = 5;
	/**
	 * Translate recure types to labels
	 *
	 * @var array
	 */
	static public $types = Array(
		self::NONE         => 'None',
		self::DAILY        => 'Daily',
		self::WEEKLY       => 'Weekly',
		self::MONTHLY_WDAY => 'Monthly (by day)',
		self::MONTHLY_MDAY => 'Monthly (by date)',
		self::YEARLY       => 'Yearly'
	);

	/**
	 * RRule type: NONE, DAILY, WEEKLY, MONTHLY_MDAY, MONTHLY_WDAY, YEARLY
	 *
	 * @var int
	 */
	public $type = self::NONE;

	/**
	 * Interval
	 *
	 * @var int
	 */
	public $interval = 1;

	/**
	 * Number for monthly byday: 1, ..., 5, -1=last weekday of month
	 *
	 * EGroupware Calendar does NOT explicitly store it, it's only implicitly defined by series start date
	 *
	 * @var int
	 */
	protected $monthly_byday_num;

	/**
	 * Number for monthly bymonthday: 1, ..., 31, -1=last day of month
	 *
	 * EGroupware Calendar does NOT explicitly store it, it's only implicitly defined by series start date
	 *
	 * @var int
	 */
	protected $monthly_bymonthday;

	/**
	 * Enddate of recurring event or null, if not ending
	 *
	 * @var DateTime
	 */
	public $enddate;
	/**
	 * Enddate of recurring event, as Ymd integer (eg. 20091111)
	 *
	 * @var int
	 */
	public $enddate_ymd;

	const SUNDAY    = 1;
	const MONDAY    = 2;
	const TUESDAY   = 4;
	const WEDNESDAY = 8;
	const THURSDAY  = 16;
	const FRIDAY    = 32;
	const SATURDAY  = 64;
	const WORKDAYS  = 62;	// Mo, ..., Fr
	const ALLDAYS   = 127;
	/**
	 * Translate weekday bitmasks to labels
	 *
	 * @var array
	 */
	static public $days = array(
		self::MONDAY    => 'Monday',
		self::TUESDAY   => 'Tuesday',
		self::WEDNESDAY => 'Wednesday',
		self::THURSDAY  => 'Thursday',
		self::FRIDAY    => 'Friday',
		self::SATURDAY  => 'Saturday',
		self::SUNDAY    => 'Sunday',
	);
	/**
	 * Bitmask of valid weekdays for weekly repeating events: self::SUNDAY|...|self::SATURDAY
	 *
	 * @var integer
	 */
	public $weekdays;

	/**
	 * Array of exception dates
	 *
	 * @var array
	 */
	public $exceptions;

	/**
	 * Starttime of series
	 *
	 * @var DateTime
	 */
	public $time;

	/**
	 * Current "position" / time
	 *
	 * @var DateTime
	 */
	public $current;

	/**
	 * Constructor
	 *
	 * The constructor accepts on DateTime (or decendents like egw_date) for all times, to work timezone-correct.
	 * The timezone of the event is determined by timezone of $time, other times get converted to that timezone.
	 *
	 * @param DateTime $time start of event in it's own timezone
	 * @param int $type self::NONE, self::DAILY, ..., self::YEARLY
	 * @param int $interval=1 1, 2, ...
	 * @param DateTime $enddate=null enddate or null for no enddate (in which case we user '+5 year' on $time)
	 * @param int $weekdays=0 self::SUNDAY=1|self::MONDAY=2|...|self::SATURDAY=64
	 * @param array $exceptions=null DateTime objects with exceptions
	 */
	public function __construct(DateTime $time,$type,$interval=1,DateTime $enddate=null,$weekdays=0,array $exceptions=null)
	{
		$this->time = $time;

		if (!in_array($type,array(self::NONE, self::DAILY, self::WEEKLY, self::MONTHLY_MDAY, self::MONTHLY_WDAY, self::YEARLY)))
		{
			throw new egw_exception_wrong_parameter(__METHOD__."($time,$type,$interval,$enddate,$data,...) type $type is NOT valid!");
		}
		$this->type = $type;

		// determine only implicit defined rules for RRULE=MONTHLY,BYDAY={-1, 1, ..., 5}{MO,..,SU}
		if ($type == self::MONTHLY_WDAY)
		{
			// check for last week of month
			if (($day = $this->time->format('d')) >= 21 && $day > self::daysInMonth($this->time)-7)
			{
				$this->monthly_byday_num = -1;
			}
			else
			{
				$this->monthly_byday_num = 1 + floor(($this->time->format('d')-1) / 7);
			}
		}
		elseif($type == self::MONTHLY_MDAY)
		{
			$this->monthly_bymonthday = (int)$this->time->format('d');
			// check for last day of month
			if ($this->monthly_bymonthday >= 28)
			{
				$test = clone $this->time;
				$test->modify('1 day');
				if ($test->format('m') != $this->time->format('m'))
				{
					$this->monthly_bymonthday = -1;
				}
			}
		}

		if (!is_numeric($interval) || $interval < 1)
		{
			throw new egw_exception_wrong_parameter(__METHOD__."($time,$type,$interval,$enddate,$data,...) interval $interval is NOT valid!");
		}
		$this->interval = (int)$interval;

		$this->enddate = $enddate;
		// no recurrence --> current date is enddate
		if ($type == self::NONE)
		{
			$enddate = clone $this->time;
		}
		// set a maximum of 5 years if no enddate given
		elseif (is_null($enddate))
		{
			$enddate = clone $this->time;
			$enddate->modify('5 year');
		}
		// convert enddate to timezone of time, if necessary
		else
		{
			$enddate->setTimezone($this->time->getTimezone());
		}
		$this->enddate_ymd = (int)$enddate->format('Ymd');

		// if no valid weekdays are given for weekly repeating, we use just the current weekday
		if (!($this->weekdays = (int)$weekdays) && ($type == self::WEEKLY || $type == self::MONTHLY_WDAY))
		{
			$this->weekdays = self::getWeekday($this->time);
		}
		if ($exceptions)
		{
			foreach($exceptions as $exception)
			{
				$exception->setTimezone($this->time->getTimezone());
				$this->exceptions[] = $exception->format('Ymd');
			}
		}
	}

	/**
	 * Get number of days in month of given date
	 *
	 * @param DateTime $time
	 * @return int
	 */
	private static function daysInMonth(DateTime $time)
	{
		list($year,$month) = explode('-',$time->format('Y-m'));
		$last_day = new egw_time();
		$last_day->setDate($year,$month-1,0);

		return (int)$last_day->format('d');
	}

	/**
	 * Return the current element
	 *
	 * @return DateTime
	 */
	public function current()
	{
		return clone $this->current;
	}

	/**
	 * Return the key of the current element, we use a Ymd integer as key
	 *
	 * @return int
	 */
	public function key()
	{
		return (int)$this->current->format('Ymd');
	}

	/**
	 * Move forward to next recurence, not caring for exceptions
	 */
	public function next_no_exception()
	{
		switch($this->type)
		{
			case self::NONE:	// need to add at least one day, to end "series", as enddate == current date
			case self::DAILY:
				$this->current->modify($this->interval.' day');
				break;

			case self::WEEKLY:
				// advance to next valid weekday
				do
				{
					// interval in weekly means event runs on valid days eg. each 2. week
					// --> on saturday we have to additionally advance interval-1 weeks
					if ($this->interval > 1 && self::getWeekday($this->current) == self::SATURDAY)
					{
						$this->current->modify(($this->interval-1).' week');
					}
					$this->current->modify('1 day');
					//echo __METHOD__.'() '.$this->current->format('l').', '.$this->current.": $this->weekdays & ".self::getWeekday($this->current)."<br />\n";
				}
				while(!($this->weekdays & self::getWeekday($this->current)));
				break;

			case self::MONTHLY_WDAY:	// iCal: BYDAY={1, ..., 5, -1}{MO..SO}
				// advance to start of next month
				list($year,$month) = explode('-',$this->current->format('Y-m'));
				$month += $this->interval+($this->monthly_byday_num < 0 ? 1 : 0);
				$this->current->setDate($year,$month,$this->monthly_byday_num < 0 ? 0 : 1);
				//echo __METHOD__."() $this->monthly_byday_num".substr(self::$days[$this->monthly_byday_wday],0,2).": setDate($year,$month,1): ".$this->current->format('l').', '.$this->current."<br />\n";
				// now advance to n-th week
				if ($this->monthly_byday_num > 1)
				{
					$this->current->modify(($this->monthly_byday_num-1).' week');
					//echo __METHOD__."() $this->monthly_byday_num".substr(self::$days[$this->monthly_byday_wday],0,2).': modify('.($this->monthly_byday_num-1).' week): '.$this->current->format('l').', '.$this->current."<br />\n";
				}
				// advance to given weekday
				while(!($this->weekdays & self::getWeekday($this->current)))
				{
					$this->current->modify(($this->monthly_byday_num < 0 ? -1 : 1).' day');
					//echo __METHOD__."() $this->monthly_byday_num".substr(self::$days[$this->monthly_byday_wday],0,2).': modify(1 day): '.$this->current->format('l').', '.$this->current."<br />\n";
				}
				break;

			case self::MONTHLY_MDAY:	// iCal: monthly_bymonthday={1, ..., 31, -1}
				list($year,$month) = explode('-',$this->current->format('Y-m'));
				$day = $this->monthly_bymonthday+($this->monthly_bymonthday < 0 ? 1 : 0);
				$month += $this->interval+($this->monthly_bymonthday < 0 ? 1 : 0);
				$this->current->setDate($year,$month,$day);
				//echo __METHOD__."() setDate($year,$month,$day): ".$this->current->format('l').', '.$this->current."<br />\n";
				break;

			case self::YEARLY:
				$this->current->modify($this->interval.' year');
				break;

			default:
				throw new egw_exception_assertion_failed(__METHOD__."() invalid type #$this->type !");
		}
	}

	/**
	 * Move forward to next recurence, taking into account exceptions
	 */
	public function next()
	{
		do
		{
			$this->next_no_exception();
		}
		while($this->exceptions && in_array($this->current->format('Ymd'),$this->exceptions));
	}

	/**
	 * Get weekday of $time as self::SUNDAY=1, ..., self::SATURDAY=64 integer mask
	 *
	 * @param DateTime $time
	 * @return int self::SUNDAY=1, ..., self::SATURDAY=64
	 */
	static protected function getWeekday(DateTime $time)
	{
		//echo __METHOD__.'('.$time->format('l').' '.$time.') 1 << '.$time->format('w').' = '.(1 << (int)$time->format('w'))."<br />\n";
		return 1 << (int)$time->format('w');
	}

	/**
	 * Rewind the Iterator to the first element (called at beginning of foreach loop)
	 */
	public function rewind()
	{
		$this->current = clone $this->time;
	}

	/**
	 * Checks if current position is valid
	 *
	 * @return boolean
	 */
	public function valid ()
	{
		return $this->current->format('Ymd') <= $this->enddate_ymd;
	}

	/**
	 * Return string represenation of RRule
	 *
	 * @return string
	 */
	function __toString( )
	{
		$str = '';
		// Repeated Events
		if($this->type != self::NONE)
		{
			list($str) = explode(' (',lang(self::$types[$this->type]));	// remove (by day/date) from Monthly

			$str_extra = array();
			if ($this->enddate)
			{
				if ($this->enddate->getTimezone() != egw_time::$user_timezone) $this->enddate->setTimezone(egw_time::$user_timezone);
				$str_extra[] = lang('ends').': '.lang($this->enddate->format('l')).', '.$this->enddate->format(egw_time::$user_dateformat);
			}
			switch ($this->type)
			{
				case self::MONTHLY_MDAY:
					$str_extra[] = ($this->monthly_bymonthday == -1 ? lang('last') : $this->monthly_bymonthday.'.').' '.lang('day');
					break;

				case self::WEEKLY:
				case self::MONTHLY_WDAY:
					$repeat_days = array();
					if ($this->weekdays == self::ALLDAYS)
					{
						$repeat_days[] = $this->type == self::WEEKLY ? lang('all') : lang('day');
					}
					elseif($this->weekdays == self::WORKDAYS)
					{
						$repeat_days[] = $this->type == self::WEEKLY ? lang('workdays') : lang('workday');
					}
					else
					{
						foreach (self::$days as $mask => $label)
						{
							if ($this->weekdays & $mask)
							{
								$repeat_days[] = lang($label);
							}
						}
					}
					if($this->type == self::WEEKLY && count($repeat_days))
					{
						$str_extra[] = lang('days repeated').': '.implode(', ',$repeat_days);
					}
					elseif($this->type == self::MONTHLY_WDAY)
					{
						$str_extra[] = ($this->monthly_byday_num == -1 ? lang('last') : $this->monthly_byday_num.'.').' '.implode(', ',$repeat_days);
					}
					break;

			}
			if($this->interval > 1)
			{
				$str_extra[] = lang('Interval').': '.$this->interval;
			}

			if(count($str_extra))
			{
				$str .= ' ('.implode(', ',$str_extra).')';
			}
		}
		return $str;
	}

	/**
	 * Get instance for a given event array
	 *
	 * @param array $event
	 * @param boolean $usertime=true true: event timestamps are usertime (default for calendar_bo::(read|search), false: servertime
	 * @return calendar_rrule
	 */
	public static function event2rrule(array $event,$usertime=true)
	{
		$timestamp_tz = $usertime ? egw_time::$user_timezone : egw_time::$server_timezone;
		$time = is_a($event['start'],'DateTime') ? $event['start'] : new egw_time($event['start'],$timestamp_tz);
		$time->setTimezone(new DateTimeZone($event['tzid']));

		if ($event['enddate'])
		{
			$enddate = is_a($event['enddate'],'DateTime') ? $event['enddate'] : new egw_time($event['enddate'],$timestamp_tz);
		}
		foreach($event['recur_exception'] as $exception)
		{
			$exceptions[] = is_a($exception,'DateTime') ? $exception : new egw_time($exception,$timestamp_tz);
		}
		return new calendar_rrule($time,$event['recur_type'],$event['recur_interval'],$enddate,$event['recur_data'],$exceptions);
	}

	/**
	 * Get recurrence data (keys 'recur_*') to merge into an event
	 *
	 * @return array
	 */
	public function rrule2event()
	{
		return array(
			'recur_type' => $this->type,
			'recur_interval' => $this->interval,
			'recur_enddate' => $this->enddate ? $this->enddate->format('ts') : null,
			'recur_data' => $this->weekdays,
			'recur_exception' => $this->exceptions,
		);
	}
}

if (isset($_SERVER['SCRIPT_FILENAME']) && $_SERVER['SCRIPT_FILENAME'] == __FILE__)	// some tests
{
	ini_set('display_errors',1);
	error_reporting(E_ALL & ~E_NOTICE);
	function lang($str) { return $str; }
	$GLOBALS['egw_info']['user']['preferences']['common']['tz'] = $_REQUEST['user-tz'] ? $_REQUEST['user-tz'] : 'Europe/Berlin';
	require_once('../../phpgwapi/inc/class.egw_time.inc.php');
	require_once('../../phpgwapi/inc/class.html.inc.php');
	require_once('../../phpgwapi/inc/class.egw_exception.inc.php');

	if (!isset($_REQUEST['time']))
	{
		$now = new egw_time('now',new DateTimeZone($_REQUEST['tz'] = 'UTC'));
		$_REQUEST['time'] = $now->format();
		$_REQUEST['type'] = calendar_rrule::WEEKLY;
		$_REQUEST['interval'] = 2;
		$now->modify('2 month');
		$_REQUEST['enddate'] = $now->format('Y-m-d');
		$_REQUEST['user-tz'] = 'Europe/Berlin';
	}
	echo "<html>\n<head>\n\t<title>Test calendar_rrule class</title>\n</head>\n<body>\n<form method='GET'>\n";
	echo "<p>Date+Time: ".html::input('time',$_REQUEST['time']).
		html::select('tz',$_REQUEST['tz'],egw_time::getTimezones())."</p>\n";
	echo "<p>Type: ".html::select('type',$_REQUEST['type'],calendar_rrule::$types)."\n".
		"Interval: ".html::input('interval',$_REQUEST['interval'])."</p>\n";
	echo "<table><tr><td>\n";
	echo "Weekdays:<br />".html::checkbox_multiselect('weekdays',$_REQUEST['weekdays'],calendar_rrule::$days,false,'','7',false,'height: 150px;')."\n";
	echo "</td><td>\n";
	echo "<p>Exceptions:<br />".html::textarea('exceptions',$_REQUEST['exceptions'],'style="height: 150px;"')."\n";
	echo "</td></tr></table>\n";
	echo "<p>Enddate: ".html::input('enddate',$_REQUEST['enddate'])."</p>\n";
	echo "<p>Display recurances in ".html::select('user-tz',$_REQUEST['user-tz'],egw_time::getTimezones())."</p>\n";
	echo "<p>".html::submit_button('calc','Calculate')."</p>\n";
	echo "</form>\n";

	$tz = new DateTimeZone($_REQUEST['tz']);
	$time = new egw_time($_REQUEST['time'],$tz);
	if ($_REQUEST['enddate']) $enddate = new egw_time($_REQUEST['enddate'],$tz);
	$weekdays = 0; foreach((array)$_REQUEST['weekdays'] as $mask) $weekdays |= $mask;
	if ($_REQUEST['exceptions']) foreach(preg_split("/[,\r\n]+ ?/",$_REQUEST['exceptions']) as $exception) $exceptions[] = new egw_time($exception);

	$rrule = new calendar_rrule($time,$_REQUEST['type'],$_REQUEST['interval'],$enddate,$weekdays,$exceptions);
	echo "<h3>".$time->format('l').', '.$time.' ('.$tz->getName().') '.$rrule."</h3>\n";
	foreach($rrule as $rtime)
	{
		$rtime->setTimezone(egw_time::$user_timezone);
		echo ++$n.': '.$rtime->format('l').', '.$rtime."<br />\n";
	}
	echo "</body>\n</html>\n";
}