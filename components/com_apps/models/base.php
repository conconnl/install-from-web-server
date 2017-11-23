<?php
/**
 * Joomla! Install From Web Server
 *
 * @copyright  Copyright (C) 2013 - 2017 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Cache\Exception\CacheExceptionInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Http\Http;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Version;

/**
 * Base model for the install from web server.
 *
 * @since  1.0
 */
class AppsModelBase extends ListModel
{
	private $_baseURL = 'index.php?format=json&option=com_apps';

	private $_categories = array();

	private $_children = array();

	private $_breadcrumbs = array();

	private $_pv = array(
		'latest'	=>	'1.1.0',
		'works'		=>	'1.0.5',
	);

	/**
	 * Method to auto-populate the model state.
	 *
	 * Note. Calling getState in this method will result in recursion.
	 *
	 * @since   1.0
	 */
	protected function populateState($ordering = null, $direction = null)
	{
		$app = Factory::getApplication();

		$this->setState('view', $app->input->getCmd('view'));
	}

	/**
	 * Fetches the category data from the JED
	 *
	 * @return  array
	 *
	 * @throws  RuntimeException if the HTTP query fails
	 */
	public function fetchCategoriesFromJed()
	{
		try
		{
			$http = $this->getHttpClient();
		}
		catch (RuntimeException $e)
		{
			throw new RuntimeException('Cannot fetch HTTP client to connect to JED', $e->getCode(), $e);
		}

		$response = $http->get('https://extensions.joomla.org/index.php?option=com_jed&view=category&layout=list&format=json&order=order&limit=-1');

		// Make sure we've gotten an expected good response
		if ($response->code !== 200)
		{
			throw new RuntimeException('Unexpected response from the JED', $response->code);
		}

		// The body should be a JSON string, if we have issues decoding it assume we have a bad response
		$categoryData = json_decode($response->body);

		if (json_last_error())
		{
			throw new RuntimeException('Unexpected response from the JED, JSON could not be decoded with error: ' . json_last_error_msg(), 500);
		}

		return $categoryData;
	}

	/**
	 * @return  Http
	 */
	public function getHttpClient(): Http
	{
		$http = HttpFactory::getHttp();
		$http->setOption('timeout', 60);
		$http->setOption('userAgent', (new Version)->getUserAgent('com_apps', true));

		return $http;
	}

	public static function getMainUrl()
	{
		return $this->_baseURL . '&view=dashboard';
	}

	public static function getCategoryUrl($categoryId)
	{
		return $this->_baseURL . '&view=category&id=' . $categoryId;
	}

	public static function getEntryListUrl( $categoryId, $limit = 30, $start = 0)
	{

	}

	public function getMainImageUrl($item)
	{
		$componentParams = ComponentHelper::getParams('com_apps');
		$default_image   = $componentParams->get('default_image_path');
		$cdn             = trim($componentParams->get('cdn', 'https://extensions.joomla.org/'), '/') . "/";

		$image = '';

		if (isset($item->logo->value[0]->path) && $item->logo->value[0]->path)
		{
			$image = $item->logo->value[0]->path;
		}
		elseif (isset($item->images->value[0]->path) && $item->images->value[0]->path)
		{
			$image = $item->images->value[0]->path;
		}

		// Replace legacy JED url with the CDN url
		$image = str_replace(['http://extensions.joomla.org/', 'https://extensions.joomla.org/'], $cdn, $image);

		// Replace API Image path with resizeDown path
		$image = preg_replace('#(logo|images)/(.*)\.#', '$2' . '_resizeDown302px133px16.', $image, 1);

		return $image;
	}

	public static function getEntryUrl($entryId)
	{
		return $this->_baseURL . '&view=extension&id=' . $entryId;
	}

	public function getCategories($catid = null)
	{
		if (empty($this->_categories))
		{
			/** @var \Joomla\CMS\Cache\Controller\CallbackController $cache */
			$cache = Factory::getCache('com_apps', 'callback');

			// These calls are always cached
			$cache->setCaching(true);

			try
			{
				// We explicitly define our own ID to keep the cache API from calculating it separately
				$items = $cache->get([$this, 'fetchCategoriesFromJed'], [], md5(__METHOD__));
			}
			catch (CacheExceptionInterface $e)
			{
				// Cache failure, let's try an HTTP request without caching
				$items = $this->fetchCategoriesFromJed();
			}
			catch (RuntimeException $e)
			{
				// Other failure, this isn't good
				Log::add(
					'Could not retrieve category data from the JED: ' . $e->getMessage(),
					Log::ERROR,
					'com_apps'
				);

				// Throw a "sanitised" Exception but nest the caught Exception for debugging
				throw new RuntimeException('Could not retrieve category data from the JED.', $e->getCode(), $e);
			}

			$breadcrumbRefs = [];

			// Array to be returned
			$this->_categories = [];

			$this->_children = [];

			foreach ($items as $item)
			{
				// Skip root category
				if (trim(strtolower($item->title->value)) === 'root')
				{
					continue;
				}

				$id       = (int) $item->id->value;
				$parentId = (int) $item->parent_id->value;

				if ((int) $item->level->value > 1)
				{
					// Ignore subitems without a parent.
					if (is_null($item->parent_id->value))
					{
						continue;
					}

					// It is a child, so let's store as a child of it's parent
					if (!array_key_exists($parentId, $this->_categories))
					{
						$this->_categories[$parentId] = new stdClass;
					}

					$parent =& $this->_categories[$parentId];

					if (!isset($parent->children))
					{
						$parent->children = [];
					}

					if (!isset($parent->children[$id]))
					{
						$parent->children[$id] = new stdClass;
					}

					$category =& $parent->children[$id];

					// Populate category with values
					$category->id          = $id;
					$category->active      = $catid == $category->id;
					$category->selected    = $category->active;
					$category->name        = $item->title->value;
					$category->alias       = $item->alias->value;
					$category->parent      = (int) $parentId;
					$category->description = '';
					$category->children    = [];

					$this->_children[] = $category;

					if ($category->active)
					{
						$this->_categories[$parentId]->active = true;

						if (!array_key_exists($parentId, $breadcrumbRefs))
						{
							$breadcrumbRefs[$parentId] = &$this->_categories[$parentId];
						}

						$breadcrumbRefs[$id] = &$category;
					}
				}
				else
				{
					// It is parent, so let's add it to the parent array
					if (!array_key_exists($id, $this->_categories))
					{
						$this->_categories[$id]           = new stdClass;
						$this->_categories[$id]->children = [];
					}
					$category =& $this->_categories[$id];

					$category->id = $id;

					if (!isset($category->active))
					{
						$category->active = $catid == $category->id;
					}

					$category->selected    = $category->active;
					$category->name        = $item->title->value;
					$category->alias       = $item->alias->value;
					$category->parent      = (int) $parentId;
					$category->description = '';

					if ($category->active)
					{
						$breadcrumbRefs[$id] = &$category;
					}
				}
			}

			if (!empty($catid))
			{
				$this->_breadcrumbs = $breadcrumbRefs;
			}
		}

		// Add the Home item
		$view  = $this->getState('view');

		$home              = new stdClass;
		$home->active      = $view == 'dashboard';
		$home->id          = 0;
		$home->name        = Text::_('COM_APPS_HOME');
		$home->alias       = 'home';
		$home->description = Text::_('COM_APPS_EXTENSIONS_DASHBOARD');
		$home->parent      = 0;
		$home->selected    = $view == 'dashboard';
		$home->children    = [];

		array_unshift($this->_categories, $home);

		return $this->_categories;
	}

	public function getBreadcrumbs($catid = null)
	{
		if (!count($this->_breadcrumbs))
		{
			$this->getCategories($catid);
		}

		return $this->_breadcrumbs;
	}

	public function getChildren($catid = null)
	{
		if (!count($this->_children))
		{
			$this->getCategories($catid);
		}

		return $this->_children;
	}

	public function getPluginUpToDate()
	{
		$remote = preg_replace('/[^\d\.]/', '', base64_decode(Factory::getApplication()->input->get('pv', '', 'base64')));
		$local  = $this->_pv;

		if (version_compare($remote, $local['latest']) >= 0)
		{
			return 1;
		}
		elseif (version_compare($remote, $local['works']) >= 0)
		{
			return 0;
		}

		return -1;
	}
}
