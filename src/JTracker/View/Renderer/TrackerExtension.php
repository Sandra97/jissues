<?php
/**
 * Part of the Joomla Tracker View Package
 *
 * @copyright  Copyright (C) 2012 - 2014 Open Source Matters, Inc. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License Version 2 or Later
 */

namespace JTracker\View\Renderer;

use Adaptive\Diff\Diff;

use App\Tracker\DiffRenderer\Html\Inline;

use g11n\g11n;

use Joomla\Database\DatabaseDriver;
use Joomla\DI\Container;

use JTracker\Application;
use JTracker\Helper\LanguageHelper;

/**
 * Twig extension class
 *
 * @since  1.0
 */
class TrackerExtension extends \Twig_Extension implements \Twig_Extension_GlobalsInterface
{
	/**
	 * Application object
	 *
	 * @var    Application
	 * @since  1.0
	 */
	private $app;

	/**
	 * Database connector
	 *
	 * @var    DatabaseDriver
	 * @since  1.0
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @since   1.0
	 */
	public function __construct(Container $container)
	{
		$this->app = $container->get('app');
		$this->db  = $container->get('db');
	}

	/**
	 * Returns the name of the extension.
	 *
	 * @return  string  The extension name.
	 *
	 * @since   1.0
	 */
	public function getName()
	{
		return 'tracker';
	}

	/**
	 * Returns a list of global variables to add to the existing list.
	 *
	 * @return  array  An array of global variables.
	 *
	 * @since   1.0
	 */
	public function getGlobals()
	{
		return [
			'uri'            => $this->app->get('uri'),
			'offset'         => $this->app->getUser()->params->get('timezone') ?: $this->app->get('system.offset'),
			'languages'      => LanguageHelper::getLanguages(),
			'languageCodes'  => LanguageHelper::getLanguageCodes(),
			'jdebug'         => JDEBUG,
			'templateDebug'  => $this->app->get('debug.template', false),
			'lang'           => $this->app->getUser()->params->get('language') ?: g11n::getCurrent(),
			'g11nJavaScript' => g11n::getJavaScript(),
			'useCDN'         => $this->app->get('system.use_cdn'),
		];
	}

	/**
	 * Returns a list of functions to add to the existing list.
	 *
	 * @return  array  An array of functions.
	 *
	 * @since   1.0
	 */
	public function getFunctions()
	{
		$functions = [
			new \Twig_SimpleFunction('translate', 'g11n3t'),
			new \Twig_SimpleFunction('g11n4t', 'g11n4t'),
			new \Twig_SimpleFunction('sprintf', 'sprintf'),
			new \Twig_SimpleFunction('stripJRoot', [$this, 'stripJRoot']),
			new \Twig_SimpleFunction('asset', [$this, 'getAssetUrl']),
			new \Twig_SimpleFunction('avatar', [$this, 'fetchAvatar']),
			new \Twig_SimpleFunction('prioClass', [$this, 'getPrioClass']),
			new \Twig_SimpleFunction('priorities', [$this, 'getPriorities']),
			new \Twig_SimpleFunction('getPriority', [$this, 'getPriority']),
			new \Twig_SimpleFunction('status', [$this, 'getStatus']),
			new \Twig_SimpleFunction('getStatuses', [$this, 'getStatuses']),
			new \Twig_SimpleFunction('translateStatus', [$this, 'translateStatus']),
			new \Twig_SimpleFunction('relation', [$this, 'getRelation']),
			new \Twig_SimpleFunction('issueLink', [$this, 'issueLink']),
			new \Twig_SimpleFunction('getRelTypes', [$this, 'getRelTypes']),
			new \Twig_SimpleFunction('getRelType', [$this, 'getRelType']),
			new \Twig_SimpleFunction('getTimezones', [$this, 'getTimezones']),
			new \Twig_SimpleFunction('getContrastColor', [$this, 'getContrastColor']),
			new \Twig_SimpleFunction('renderDiff', [$this, 'renderDiff']),
			new \Twig_SimpleFunction('renderLabels', [$this, 'renderLabels']),
			new \Twig_SimpleFunction('arrayDiff', [$this, 'arrayDiff']),
			new \Twig_SimpleFunction('userTestOptions', [$this, 'getUserTestOptions']),
			new \Twig_SimpleFunction('getMilestoneTitle', [$this, 'getMilestoneTitle']),
		];

		if (!JDEBUG)
		{
			array_push($functions, new \Twig_SimpleFunction('dump', [$this, 'dump']));
		}

		return $functions;
	}

	/**
	 * Returns a list of filters to add to the existing list.
	 *
	 * @return  array  An array of filters
	 *
	 * @since   1.0
	 */
	public function getFilters()
	{
		return [
			new \Twig_SimpleFilter('basename', 'basename'),
			new \Twig_SimpleFilter('get_class', 'get_class'),
			new \Twig_SimpleFilter('json_decode', 'json_decode'),
			new \Twig_SimpleFilter('stripJRoot', [$this, 'stripJRoot']),
			new \Twig_SimpleFilter('contrastColor', [$this, 'getContrastColor']),
			new \Twig_SimpleFilter('labels', [$this, 'renderLabels']),
			new \Twig_SimpleFilter('yesno', [$this, 'yesNo']),
			new \Twig_SimpleFilter('_', 'g11n3t'),
			new \Twig_SimpleFilter('mergeStatus', [$this, 'getMergeStatus']),
			new \Twig_SimpleFilter('mergeBadge', [$this, 'renderMergeBadge']),
		];
	}

	/**
	 * Replaces the Joomla! root path defined by the constant "JPATH_ROOT" with the string "JROOT".
	 *
	 * @param   string  $string  The string to process.
	 *
	 * @return  mixed
	 *
	 * @since   1.0
	 */
	public function stripJRoot($string)
	{
		return str_replace(JPATH_ROOT, 'JROOT', $string);
	}

	/**
	 * Fetch an avatar.
	 *
	 * @param   string   $userName  The user name.
	 * @param   integer  $width     The with in pixel.
	 * @param   string   $class     The class.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @todo    Refactor avatar paths to use the media directory
	 */
	public function fetchAvatar($userName = '', $width = 0, $class = '')
	{
		$base = $this->app->get('uri.base.path');

		$avatar = $userName ? $userName . '.png' : 'user-default.png';

		$width = $width ? ' style="width: ' . $width . 'px"' : '';
		$class = $class ? ' class="' . $class . '"' : '';

		return '<img'
		. $class
		. ' alt="avatar ' . $userName . '"'
		. ' src="' . $base . 'images/avatars/' . $avatar . '"'
		. $width
		. ' />';
	}

	/**
	 * Get a CSS class according to the item priority.
	 *
	 * @param   integer  $priority  The priority
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getPrioClass($priority)
	{
		switch ($priority)
		{
			case 1 :
				return 'badge-important';

			case 2 :
				return 'badge-warning';

			case 3 :
				return 'badge-info';

			case 4 :
				return 'badge-inverse';

			default :
				return '';
		}
	}

	/**
	 * Get a text list of issue priorities.
	 *
	 * @return  array  The list of priorities.
	 *
	 * @since   1.0
	 */
	public function getPriorities()
	{
		return [
			1 => g11n3t('Critical'),
			2 => g11n3t('Urgent'),
			3 => g11n3t('Medium'),
			4 => g11n3t('Low'),
			5 => g11n3t('Very low')
		];
	}

	/**
	 * Get the priority text.
	 *
	 * @param   integer  $id  The priority id.
	 *
	 * @return string
	 *
	 * @since   1.0
	 */
	public function getPriority($id)
	{
		$priorities = $this->getPriorities();

		return isset($priorities[$id]) ? $priorities[$id] : 'N/A';
	}

	/**
	 * Dummy function to prevent throwing exception on dump function in the non-debug mode.
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */
	public function dump()
	{
		return;
	}

	/**
	 * Retrieves a human friendly relationship for a given type
	 *
	 * @param   string  $relation  Relation type
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getRelation($relation)
	{
		$relations = [
			'duplicate_of' => g11n3t('Duplicate of'),
			'related_to' => g11n3t('Related to'),
			'not_before' => g11n3t('Not before'),
			'pr_for' => g11n3t('Pull Request for')
		];

		return $relations[$relation];
	}

	/**
	 * Get a status object based on its id.
	 *
	 * @param   integer  $id  The id
	 *
	 * @return  object
	 *
	 * @since   1.0
	 * @throws  \UnexpectedValueException
	 */
	public function getStatus($id)
	{
		static $statuses = [];

		if (!$statuses)
		{
			$items = $this->db->setQuery(
				$this->db->getQuery(true)
					->from($this->db->quoteName('#__status'))
					->select('*')
			)->loadObjectList();

			foreach ($items as $status)
			{
				$status->cssClass = $status->closed ? 'error' : 'success';
				$statuses[$status->id] = $status;
			}
		}

		if (!array_key_exists($id, $statuses))
		{
			throw new \UnexpectedValueException('Unknown status id:' . (int) $id);
		}

		return $statuses[$id];
	}

	/**
	 * Get a text list of statuses.
	 *
	 * @param   int  $state  The state of issue: 0 - open, 1 - closed.
	 *
	 * @return  array  The list of statuses.
	 *
	 * @since   1.0
	 */
	public function getStatuses($state = null)
	{
		switch ((string) $state)
		{
			case '0':
				$statuses = [
					1 => g11n3t('New'),
					2 => g11n3t('Confirmed'),
					3 => g11n3t('Pending'),
					4 => g11n3t('Ready To Commit'),
					6 => g11n3t('Needs Review'),
					7 => g11n3t('Information Required')
				];
				break;

			case '1':
				$statuses = [
					5 => g11n3t('Fixed in Code Base'),
					8 => g11n3t('Unconfirmed Report'),
					9 => g11n3t('No Reply'),
					10 => g11n3t('Closed'),
					11 => g11n3t('Expected Behaviour'),
					12 => g11n3t('Known Issue'),
					13 => g11n3t('Duplicate Report')
				];
				break;

			default:
				$statuses = [
					1 => g11n3t('New'),
					2 => g11n3t('Confirmed'),
					3 => g11n3t('Pending'),
					4 => g11n3t('Ready To Commit'),
					6 => g11n3t('Needs Review'),
					7 => g11n3t('Information Required'),
					5 => g11n3t('Fixed in Code Base'),
					8 => g11n3t('Unconfirmed Report'),
					9 => g11n3t('No Reply'),
					10 => g11n3t('Closed'),
					11 => g11n3t('Expected Behaviour'),
					12 => g11n3t('Known Issue'),
					13 => g11n3t('Duplicate Report')
				];
		}

		return $statuses;
	}

	/**
	 * Retrieves the translated status name for a given ID
	 *
	 * @param   integer  $id  Status ID
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function translateStatus($id)
	{
		$statuses = $this->getStatuses();

		return $statuses[$id];
	}

	/**
	 * Get a contrasting color (black or white).
	 *
	 * http://24ways.org/2010/calculating-color-contrast/
	 *
	 * @param   string  $hexColor  The hex color.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getContrastColor($hexColor)
	{
		$r = hexdec(substr($hexColor, 0, 2));
		$g = hexdec(substr($hexColor, 2, 2));
		$b = hexdec(substr($hexColor, 4, 2));
		$yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;

		return ($yiq >= 128) ? 'black' : 'white';
	}

	/**
	 * Render a list of labels.
	 *
	 * @param   string  $idsString  Comma separated list of IDs.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function renderLabels($idsString)
	{
		static $labels;

		if (!$labels)
		{
			$labels = $this->app->getProject()->getLabels();
		}

		$html = [];

		$ids = ($idsString) ? explode(',', $idsString) : [];

		foreach ($ids as $id)
		{
			if (array_key_exists($id, $labels))
			{
				$bgColor = $labels[$id]->color;
				$color   = $this->getContrastColor($bgColor);
				$name    = $labels[$id]->name;
			}
			else
			{
				$bgColor = '000000';
				$color   = 'ffffff';
				$name    = '?';
			}

			$html[] = '<span class="label"' . ' style="background-color: #' . $bgColor . '; color: ' . $color . ';">';
			$html[] = $name;
			$html[] = '</span>';
		}

		return implode("\n", $html);
	}

	/**
	 * Get HTML for an issue link.
	 *
	 * @param   integer  $number  Issue number.
	 * @param   boolean  $closed  Issue closed status.
	 * @param   string   $title   Issue title.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function issueLink($number, $closed, $title = '')
	{
		$html = array();

		$title = ($title) ? : ' #' . $number;
		$href = $this->app->get('uri')->base->path
			. 'tracker/' . $this->app->getProject()->alias . '/' . $number;

		$html[] = '<a href="' . $href . '"' . ' title="' . $title . '"' . '>';
		$html[] = $closed ? '<del># ' . $number . '</del>' : '# ' . $number;
		$html[] = '</a>';

		return implode("\n", $html);
	}

	/**
	 * Get relation types.
	 *
	 * @return  array
	 *
	 * @since   1.0
	 */
	public function getRelTypes()
	{
		static $relTypes = [];

		if (!$relTypes)
		{
			$relTypes = $this->db->setQuery(
				$this->db->getQuery(true)
					->from($this->db->quoteName('#__issues_relations_types'))
					->select($this->db->quoteName('id', 'value'))
					->select($this->db->quoteName('name', 'text'))
			)->loadObjectList();
		}

		return $relTypes;
	}

	/**
	 * Get the relation type text.
	 *
	 * @param   integer  $id  The relation id.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getRelType($id)
	{
		foreach ($this->getRelTypes() as $relType)
		{
			if ($relType->value == $id)
			{
				return $relType->text;
			}
		}

		return '';
	}

	/**
	 * Generate a localized yes/no message.
	 *
	 * @param   integer  $value  A value that evaluates to TRUE or FALSE.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function yesNo($value)
	{
		return $value ? g11n3t('Yes') : g11n3t('No');
	}

	/**
	 * Get the timezones.
	 *
	 * @return  array  The timezones.
	 *
	 * @since   1.0
	 */
	public function getTimezones()
	{
		return \DateTimeZone::listIdentifiers(\DateTimeZone::ALL);
	}

	/**
	 * Generate HTML output for a "merge status badge".
	 *
	 * @param   string  $status  The merge status.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function renderMergeBadge($status)
	{
		switch ($status)
		{
			case 'success':
				$class = 'success';
				break;
			case 'pending':
				$class = 'warning';
				break;
			case 'error':
			case 'failure':
				$class = 'important';
				break;

			default:
				throw new \RuntimeException('Unknown status: ' . $status);
		}

		return '<span class="badge badge-' . $class . '">' . $this->getMergeStatus($status) . '</span>';
	}

	/**
	 * Generate a translated merge status.
	 *
	 * @param   string  $status  The merge status.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 * @throws  \RuntimeException
	 */
	public function getMergeStatus($status)
	{
		switch ($status)
		{
			case 'success':
				return g11n3t('Success');

			case 'pending':
				return g11n3t('Pending');

			case 'error':
				return g11n3t('Error');

			case 'failure':
				return g11n3t('Failure');
		}

		throw new \RuntimeException('Unknown status: ' . $status);
	}

	/**
	 * Render the differences between two text strings.
	 *
	 * @param   string   $old              The "old" text.
	 * @param   string   $new              The "new" text.
	 * @param   boolean  $showLineNumbers  To show line numbers.
	 * @param   boolean  $showHeader       To show the table header.
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function renderDiff($old, $new, $showLineNumbers = true, $showHeader = true)
	{
		$options = [];

		$diff = new Diff(explode("\n", $old), explode("\n", $new), $options);

		$renderer = new Inline;

		$renderer->setShowLineNumbers($showLineNumbers);
		$renderer->setShowHeader($showHeader);

		return $diff->render($renderer);
	}

	/**
	 * Get the difference of two comma separated value strings.
	 *
	 * @param   string  $a  The "a" string.
	 * @param   string  $b  The "b" string.
	 *
	 * @return string  difference values comma separated
	 *
	 * @since   1.0
	 */
	public function arrayDiff($a, $b)
	{
		$as = explode(',', $a);
		$bs = explode(',', $b);

		return implode(',', array_diff($as, $bs));
	}

	/**
	 * Get a user test option string.
	 *
	 * @param   integer  $id  The option ID.
	 *
	 * @return  mixed array or string if an ID is given.
	 *
	 * @since   1.0
	 */
	public function getUserTestOptions($id = null)
	{
		static $options = [];

		$options = $options ? : [
			0 => g11n3t('Not tested'),
			1 => g11n3t('Tested successfully'),
			2 => g11n3t('Tested unsuccessfully')
		];

		return ($id !== null && array_key_exists($id, $options)) ? $options[$id] : $options;
	}

	/**
	 * Get the title of the milestone by id
	 *
	 * @param   integer  $id  The id of the milestone
	 *
	 * @return  string  The title of the milestone
	 *
	 * @since   1.0
	 */
	public function getMilestoneTitle($id)
	{
		static $milestones = [];

		if (!$milestones)
		{
			$milestones = $this->db->setQuery(
				$this->db->getQuery(true)
					->select($this->db->quoteName(['milestone_id', 'title']))
					->from($this->db->quoteName('#__tracker_milestones'))
			)->loadObjectList();
		}

		foreach ($milestones as $milestone)
		{
			if ($milestone->milestone_id == $id)
			{
				return $milestone->title;
			}
		}

		return '';
	}

	/**
	 * Returns the public URL of an asset
	 *
	 * @param   string  $path  The path to a media file relative to the site's media directory
	 *
	 * @return  string
	 *
	 * @since   1.0
	 */
	public function getAssetUrl($path)
	{
		return $this->app->get('uri.media.full') . $path;
	}
}
