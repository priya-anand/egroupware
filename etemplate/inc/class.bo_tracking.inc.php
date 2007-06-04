<?php
/**
 * eGroupWare - abstract base class for tracking (history log, notifications, ...)
 *
 * @link http://www.egroupware.org
 * @author Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @package etemplate
 * @subpackage api
 * @copyright (c) 2007 by Ralf Becker <RalfBecker-AT-outdoor-training.de>
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 * @version $Id$ 
 */

/**
 * Abstract base class for trackering:
 *  - logging all modifications of an entry
 *  - notifying users about changes in an entry
 * 
 * You need to extend these class in your application:
 *	1. set the required class-vars: app, id_field
 *	2. optional set class-vars: creator_field, assigned_field, check2prefs
 *	3. implement the required methods: get_config, get_details
 *	4. optionally re-implement: get_subject, get_body, get_attachments, get_link, get_message
 * They are all documented in this file via phpDocumentor comments.
 */
class bo_tracking
{
	/**
	 * Application we are tracking
	 *
	 * @var string
	 */
	var $app;
	/**
	 * Name of the id-field, used as id in the history log (required!)
	 *
	 * @var string
	 */
	var $id_field;
	/**
	 * Name of the field with the creator id, if the creator of an entry should be notified
	 *
	 * @var string
	 */
	var $creator_field;
	/**
	 * Name of the field with the id(s) of assinged users, if they should be notified
	 *
	 * @var string
	 */
	var $assigned_field;
	/**
	 * Can be used to map the following prefs to different names:
	 *  - notify_creator  - user wants to be notified for items he created
	 *  - notify_assigned - user wants to be notified for items assigned to him
	 *  - notify_html     - user wants his notifications as html-email
	 * @var array
	 */
	var $check2pref;
	/**
	 * Translate field-name to 2-char history status
	 *
	 * @var array
	 */
	var $field2history = array();
	/**
	 * Should the user (passed to the track method or current user if not passed) be used as sender or get_config('sender')
	 *
	 * @var boolean
	 */
	var $prefer_user_as_sender = true;
	
	/**
	 * Array with error-messages if track($data,$old) returns false
	 *
	 * @var array
	 */
	var $errors = array();

	/**
	 * instance of the historylog object for the app we are tracking
	 *
	 * @access private
	 * @var historylog
	 */
	var $historylog;
	
	/**
	 * Current user, can be set via bo_tracking::track(,,$user)
	 * 
	 * @access private
	 * @var int;
	 */
	var $user;
	/**
	 * Saved user preferences, if send_notifications need to set an other language
	 *
	 * @access private
	 * @var array
	 */
	var $save_prefs;
	/**
	 * Datetime format of the currently notified user (send_notificaton)
	 *
	 * @var string
	 */
	var $datetime_format;
	/**
	 * Offset to server-time of the currently notified user (send_notificaton)
	 *
	 * @var int
	 */
	var $tz_offset_s;

	/**
	 * Get a config value, which can depend on $data and $old
	 * 
	 * Need to be implemented in your extended tracking class!
	 *
	 * @abstract 
	 * @param string $what possible values are:
	 * 	- 'copy' array of email addresses notifications should be copied too, can depend on $data
	 *  - 'lang' string lang code for copy mail
	 *  - 'subject' string subject line for the notification of $data,$old, defaults to link-title
	 * @param array $data current entry
	 * @param array $old=null old/last state of the entry or null for a new entry
	 * @return mixed
	 */
	function get_config($name,$data,$old=null)
	{
		die('You need to extend the bo_tracking class, to be able to use it (abstract base class)!');		
	}
	
	/**
	 * Tracks the changes in one entry $data, by comparing it with the last version in $old
	 *
	 * @param array $data current entry
	 * @param array $old=null old/last state of the entry or null for a new entry
	 * @param int $user=null user who made the changes, default to current user
	 * @return int/boolean false on error, integer number of changes logged or true for new entries ($old == null)
	 */
	function track($data,$old=null,$user=null)
	{
		$this->user = !is_null($user) ? $user : $GLOBALS['egw_info']['user']['account_id'];

		$changes = true;

		if ($old)
		{
			$changes = $this->save_history($data,$old);
		}
		if (!$this->do_notifications($data,$old))
		{
			$changes = false;
		}
		return $changes;
	}
	
	/**
	 * Save changes to the history log
	 *
	 * @internal use only track($data,$old)
	 * @param array $data current entry
	 * @param array $old=null old/last state of the entry or null for a new entry
	 * @param int number of log-entries made
	 */
	function save_history($data,$old)
	{
		$changes = 0;
		foreach($this->field2history as $name => $status)
		{
			if ($old[$name] != $data[$name])
			{
				if (!is_object($this->historylog))
				{
					require_once(EGW_API_INC.'/class.historylog.inc.php');
					$this->historylog =& new historylog($this->app);
				}
				$this->historylog->add($status,$data[$this->id_field],$data[$name],$old[$name]);
				++$changes;
			}
		}
		return $changes;
	}
	
	/**
	 * sending all notifications for the changed entry
	 *
	 * @internal use only track($data,$old,$user)
	 * @param array $data current entry
	 * @param array $old=null old/last state of the entry or null for a new entry
	 * @return boolean true on success, false on error (error messages are in $this->errors)
	 */
	function do_notifications($data,$old)
	{
		$this->errors = $email_sent = array();

		// entry creator
		if ($this->creator_field && ($email = $GLOBALS['egw']->accounts->id2name($data[$this->creator_field],'account_email')) &&
			!in_array($email, $email_sent)) 
		{
			$this->send_notification($data,$old,$email,$data[$this->creator_field],'notify_creator');
			$email_sent[] = $email;	
		}

		// assigned / responsible users
		if ($this->assigned_field)
		{
			$assignees = $old_assignees = array();
			if ($data[$this->assigned_field])	// current assignments
			{
				$assignees = is_array($data[$this->assigned_field]) ? 
					$data[$this->assigned_field] : explode(',',$data[$this->assigned_field]);
			}
			if ($old && $old[$this->assigned_field])
			{
				$old_assignees = is_array($old[$this->assigned_field]) ? 
					$old[$this->assigned_field] : explode(',',$old[$this->assigned_field]);
			}
			foreach(array_unique(array_merge($assignees,$old_assignees)) as $assignee)
			{
				if (!$assignee) continue;

				// item assignee is a user
				if ($GLOBALS['egw']->accounts->get_type($assignee) == 'u')
				{
					if (($email = $GLOBALS['egw']->accounts->id2name($assignee,'account_email')) && !in_array($email, $email_sent))
					{
						$this->send_notification($old,$email,$data['tr_assigned'],'notify_assigned');
						$email_sent[] = $email;	
					}
				}
				else	// item assignee is a group
				{
					foreach($GLOBALS['egw']->accounts->members($assignee,true) as $u)
					{
						if ($email = $GLOBALS['egw']->accounts->id2name($u,'account_email') && !in_array($email, $email_sent))
						{
							$this->send_notification($old,$email,$u,'notify_assigned');
							$email_sent[] = $email;
						}
					}
				}
			}
		}

		// notification copies
		if (($copies = $this->get_config('copy',$data,$old)))
		{
			$lang = $this->get_config('lang',$data,$old);
			foreach($copies as $email)
			{
				if (strchr($email,'@') !== false && !in_array($email, $email_sent)) 
				{
					$this->send_notification($data,$old,$email,$lang,'notify_copy');
					$email_sent[] = $email;
				}
			}
		}

		// restore the user enviroment
		if ($this->save_prefs) $GLOBALS['egw_info']['user'] = $this->save_prefs; unset($this->save_prefs);
		if ($GLOBALS['egw_info']['user']['preferences']['common']['lang'] != $GLOBALS['egw']->translation->userlang)
		{
			$GLOBALS['egw']->translation->init();			
		}
		return !count($this->errors);
	}
	
	/**
	 * Send a popup notification via the notification app
	 *
	 * @param int $user
	 * @param string $message
	 * @return boolean true on success, false on error
	 */
	function popup_notification($user,$message)
	{
		static $is_php51;
		if (is_null($is_php51)) $is_php51 = version_compare(phpversion(),'5.1.0','>=');

		if (!$is_php51) return false;
		
		// check if the to notifying user has rights to run the notifcation app
		$ids = $GLOBALS['egw']->accounts->memberships($user,true);
		$ids[] = $user;
		if (!$GLOBALS['egw']->acl->get_specific_rights_for_account($ids,'run','notifications')) return false;

		if (!include_once(EGW_INCLUDE_ROOT. '/notifications/inc/class.notification.inc.php')) return false;

		return is_null(notification::notify(array($user),$message));	// return the exeception on error
	}

	/**
	 * Sending a notification to the given email-address
	 *
	 * @internal use only track($data,$old,$user)
	 * @param array $data current entry
	 * @param array $old=null old/last state of the entry or null for a new entry
	 * @param string $email address to send the notification to
	 * @param string $user_or_lang='en' user-id or 2 char lang-code for a non-system user
	 * @param string $check=null pref. to check if a notification is wanted
	 * @return boolean true on success or false on error (error-message is in $this->errors)
	 */
	function send_notification($data,$old,$email,$user_or_lang,$check=null)
	{
		if (!$email) return false;
		
		//echo "<p>botracker::send_notification(,'$email',$user_or_lang)</p>\n";
		//echo "old"; _debug_array($old);
		//echo "data"; _debug_array($data);
		
		if (!$this->save_prefs) $this->save_prefs = $GLOBALS['egw_info']['user'];
		
		if (is_numeric($user_or_lang))	// user --> read everything from his prefs
		{
			if ($user_or_lang != $this->user)
			{
				$GLOBALS['egw']->preferences->preferences($user_or_lang);
				$GLOBALS['egw_info']['user']['preferences'] = $GLOBALS['egw']->preferences->read_repository();
			}
			if ($check && !$GLOBALS['egw_info']['user']['preferences'][$this->app][$this->check2pref ? $this->check2pref[$check] : $check])
			{
				return false;	// no notification requested
			}
			// notification via notification app.
			$this->popup_notification($user_or_lang,$this->get_subject($data,$old));
		}
		else
		{
			// for the notification copy, we use the default-prefs plus the language from the the tracker config
			$GLOBALS['egw_info']['user']['preferences'] = $GLOBALS['egw']->preferences->default;
			$GLOBALS['egw_info']['user']['preferences']['common']['lang'] = $user_or_lang;
		}
		$this->datetime_format = $GLOBALS['egw_info']['user']['preferences']['common']['dateformat'].' '.
			($GLOBALS['egw_info']['user']['preferences']['common']['timeformat'] != 12 ? 'H:i' : 'h:i a');
		$this->tz_offset_s = 3600 * $GLOBALS['egw_info']['user']['preferences']['common']['tz_offset'];

		if ($lang != $GLOBALS['egw']->translation->userlang)	// load the right language if needed
		{
			$GLOBALS['egw']->translation->init();
		}

		// PHPMailer aka send-class, seems not to be able to send more then one mail, IF we need to authenticate to the SMTP server
		// There for the object is newly created for ever mail, 'til this get fixed in PHPMailer.
		//if(!is_object($GLOBALS['egw']->send))
		{
			require_once(EGW_API_INC.'/class.send.inc.php');
			$GLOBALS['egw']->send = $send =& new send();
		}
		//$send = &$GLOBALS['egw']->send;
		$send->ClearAddresses();
		$send->ClearAttachments();

		// does the user wants html-emails
		$html_email = !!$GLOBALS['egw_info']['user']['preferences']['tracker'][$this->check2pref ? $this->check2pref['notify_html'] : 'notify_html'];
		$send->IsHTML($html_email);		

		if (preg_match('/^(.+) *<(.+)>/',$email,$matches))	// allow to use eg. "Ralf Becker <ralf@egw.org>" as address
		{
			$send->AddAddress($matches[2],$matches[1]);
		}
		else
		{
			$send->AddAddress($email,is_numeric($user_or_lang) ? $GLOBALS['egw']->accounts->id2name($user_or_lang,'account_fullname') : '');
		}
		$send->AddCustomHeader("X-eGroupWare-type: {$this->app}update");

		$sender = $this->get_sender($user,$data,$old);
		if (preg_match('/^(.+) *<(.+)>/',$sender,$matches))	// allow to use eg. "Ralf Becker <ralf@egw.org>" as sender
		{
			$send->From = $matches[2];
			$send->FromName = $matches[1];
		}
		else
		{
			$send->From = $sender;
			$send->FromName = '';
		}
		$send->Subject = $this->get_subject($data,$old);

		$send->Body = $this->get_body($html_email,$data,$old);
		
		foreach($this->get_attachments($data,$old) as $attachment)
		{
			if (isset($attachment['content']))
			{
				$send->AddStringAttachment($attachment['content'],$attachment['filename'],$attachment['encoding'],$attachment['mimetype']);
			}
			elseif (isset($attachment['path']))
			{
				$send->AddAttachment($attachment['path'],$attachment['filename'],$attachment['encoding'],$attachment['$mimetype']);
			}
		}

		if (!$send->Send())
		{
			$this->errors[] = lang('Error while notifying %1: %2',$email,$send->ErrorInfo);
			return false;
		}
		return true;
	}
	
	/**
	 * Return date+time formatted for the currently notified user (send_notification)
	 *
	 * @param int $timestamp
	 * @return string
	 */
	function datetime($timestamp)
	{
		return date($this->datetime_format,$timestamp+$this->tz_offset_s);
	}
	
	/**
	 * Get sender address
	 * 
	 * The default implementation prefers depending on the prefer_user_as_sender class-var the user over
	 * what is returned by get_config('sender'). 
	 * 
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_sender($data,$old)
	{
		$sender = $this->get_config('sender',$data,$old);
		
		if (($this->prefer_user_as_sender || !$sender) && $this->user && 
			($email = $GLOBALS['egw']->accounts->id2name($this->user,'account_email')))
		{
			$name = $GLOBALS['egw']->accounts->id2name($this->user,'account_fullname');
			
			return $name ? $name.' <'.$email.'>' : $email;
		}
		return $sender ? $sender : 'eGroupWare '.lang($this->app).' <noreply@'.$GLOBALS['egw_info']['server']['mail_suffix'];
	}

	/**
	 * Get the subject for a given entry, can be reimplemented
	 * 
	 * Default implementation uses the link-title
	 *
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_subject($data,$old)
	{
		if (!is_object($GLOBALS['egw']->link))
		{
			require_once(EGW_API_INC.'/class.bolink.inc.php');
			$GLOBALS['egw']->link =& new bolink();
		}
		return $GLOBALS['egw']->link->title($this->app,$data[$this->id_field]);
	}

	/**
	 * Get the modified / new message (1. line of mail body) for a given entry, can be reimplemented
	 * 
	 * Default implementation does nothing
	 *
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_message($data,$old)
	{
		return '';
	}

	/**
	 * Get a link to view the entry, can be reimplemented
	 * 
	 * Default implementation checks get_config('link') (appending the id) or link::view($this->app,$id)
	 *
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_link($data,$old)
	{
		if (($link = $this->get_config('link',$data,$old)))
		{
			if (strpos($link,$this->id_field.'=') === false)
			{
				$link .= '&'.$this->id_field.'='.$data[$this->id_field];
			}
		}
		elseif (($view = $GLOBALS['egw']->link->view($this->app,$data[$this->id_field])))
		{
			$link = preg_replace('/(sessionid|kp3|domain)=[^&]+&?/','',$GLOBALS['egw']->link('/index.php',$view));
			
			if ($link{0} == '/')
			{
				$link = ($_SERVER['HTTPS'] || $GLOBALS['egw_info']['server']['enforce_ssl'] ? 'https://' : 'http://').
					($GLOBALS['egw_info']['server']['hostname'] ? $GLOBALS['egw_info']['server']['hostname'] : $_SERVER['HTTP_HOST']).$link;
			}
			if ($GLOBALS['egw']->link->is_popup($this->app,'view')) $link .= '&nopopup=1';
		}
		return $link;
	}
	
	/**
	 * Get the body of the notification message, can be reimplemented
	 *
	 * @param boolean $html_email
	 * @param array $data
	 * @param array $old
	 * @return string
	 */
	function get_body($html_email,$data,$old)
	{
		$body = '';
		if ($html_email)
		{	
			$body = "<html>\n<body>\n".'<table cellspacing="2" cellpadding="0" border="0" width="100%">'."\n";
		}
		// new or modified message
		if (($message = $this->get_message($data,$old)))
		{
			$body .= $this->format_line($html_email,'message',false,$message);
		}
		if (($link = $this->get_link($data,$old)))
		{
			$body .= $this->format_line($html_email,'link',false,lang('You can respond by visiting:'),$link);
		}
		foreach($this->get_details($data) as $name => $detail)
		{
			$modified = $old && $data[$name] != $old[$name];
			if ($modified) error_log("data[$name]='{$data[$name]}', old[$name]='{$old[$name]}' --> modified=".(int)$modified);
			if (empty($detail['value']) && !$modified) continue;	// skip unchanged, empty values
			
			$body .= $this->format_line($html_email,$detail['type'],$modified,
				($detail['label'] ? $detail['label'].': ':'').$detail['value']);
		}
		if ($html_email)
		{
			$body .= "</table>\n</body>\n</html>\n";
		}
		return $body;
	}
	
	/**
	 * Format one line to the mail body
	 *
	 * @internal 
	 * @param boolean $html_mail
	 * @param string $type 'link', 'message', 'summary', 'multiline', 'reply' and ''=regular content
	 * @param boolean $modified mark field as modified
	 * @param string $line
	 * @param string $link=null
	 * @return string
	 */
	function format_line($html_mail,$type,$modified,$line,$link=null)
	{
		$content = '';
		
		if ($html_mail)
		{
			$color = $modified ? 'red' : false;
			$size  = 'small';
			$bold = false;
			$background = '#FFFFF1';
			switch($type)
			{
				case 'message':
					$background = '#D3DCE3;';
					$bold = true;
					break;
				case 'link':
					$background = '#F1F1F1';
					break;
				case 'summary':
					$background = '#F1F1F1';
					$bold = true;
					break;
				case 'multiline':
					$line = nl2br($line);
					break;
				case 'reply':
					$background = '#F1F1F1';					
					break;
				default:
					$size = 'x-small';
			}
			$style = ($bold ? 'font-weight:bold;' : '').($size ? 'font-size:'.$size.';' : '').($color?'color:'.$color:'');
			
			$content = '<tr style="background-color: '.$background.';"><td style="'.$style.'">';
		}
		else	// text-mail
		{
			if ($type == 'reply') $content = str_repeat('-',64)."\n"; 
			if ($modified) $content .= '> ';
		}
		$content .= $line;
		
		if ($link)
		{
			$content .= ' ';
			if ($html_mail) $content .= '<a href="'.$link.'" target="_blank">';
			$content .= $link;
			if ($html_mail) $content .= '</a>';
		}
		if ($html_mail) $content .= '</td></tr>';
		
		$content .= "\n";
		
		return $content;
	}
	
	/**
	 * Get the attachments for a notificaton mail
	 *
	 * @param array $data
	 * @param array $old
	 * @return array with values for either 'content' or 'path' and optionally 'mimetype', 'filename' and 'encoding'
	 */
	function get_attachments($data,$old)
	{
		return array();
	}
}