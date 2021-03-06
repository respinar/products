<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2016 Leo Feyer
 *
 * @package   product
 * @author    Hamid Abbaszadeh
 * @license   LGPL-3.0+
 * @copyright 2014-2016
 */


/**
 * Namespace
 */
namespace Respinar\Products\Frontend\Module;

use Respinar\Products\Model\ProductModel;
use Respinar\Products\Model\ProductCatalogModel;
use Contao\CoreBundle\Exception\PageNotFoundException;


/**
 * Class ModuleProductDetail
 *
 * @copyright  2014
 * @author     Hamid Abbaszadeh
 * @package    Devtools
 */
class ModuleProductDetail extends ModuleProduct
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_product_detail';

	/**
	 * Display a wildcard in the back end
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new \BackendTemplate('be_wildcard');

			$objTemplate->wildcard = '### ' . utf8_strtoupper($GLOBALS['TL_LANG']['FMD']['product_detail'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		// Set the item from the auto_item parameter
		if (!isset($_GET['items']) && $GLOBALS['TL_CONFIG']['useAutoItem'] && isset($_GET['auto_item']))
		{
			\Input::setGet('items', \Input::get('auto_item'));
		}

		$this->product_catalogs = $this->sortOutProtected(deserialize($this->product_catalogs));

		if (TL_MODE == 'FE')
		{
            $GLOBALS['TL_JAVASCRIPT'][] = 'system/modules/products/assets/vendor/rateit/jquery.rateit.min.js|static';
            $GLOBALS['TL_CSS'][] = 'system/modules/products/assets/vendor/rateit/rateit.css|static';
        }

		return parent::generate();
	}


	/**
	 * Generate the module
	 */
	protected function compile()
	{

		global $objPage;

		$this->Template->products          = '';
		$this->Template->referer           = 'javascript:history.go(-1)';
		$this->Template->back              = $GLOBALS['TL_LANG']['MSC']['goBack'];
		$this->Template->relateds_headline = $GLOBALS['TL_LANG']['MSC']['relateds_headline'];

		$objProduct = ProductModel::findPublishedByParentAndIdOrAlias(\Input::get('items'),$this->product_catalogs);

		if (null === $objProduct)
		{
			throw new PageNotFoundException('Page not found: ' . \Environment::get('uri'));
		}

		// Update the database
		$this->Database->prepare("UPDATE tl_product SET `visit`=`visit`+1 WHERE id=?")
					   ->execute($objProduct->id);

		// Overwrite the page title
		if ($objProduct->pageTitle)
		{
			$objPage->pageTitle = $objProduct->pageTitle;
		}
		elseif ($objProduct->title)
		{
			$objPage->pageTitle = strip_tags(\StringUtil::stripInsertTags($objProduct->title));
		}

		// Overwrite the page description
		if ($objProduct->description)
		{
			$objPage->description = $this->prepareMetaDescription($objProduct->description);
		}

		$arrProduct = $this->parseProduct($objProduct);

		$this->Template->product = $arrProduct;

		if ($objProduct->singleSRC != '')
		{
			$objModel = \FilesModel::findByUuid($objProduct->singleSRC);
		}

		$ogTagsURL = self::replaceInsertTags('{{env::path}}{{env::request}}');
		$ogTagsImage = self::replaceInsertTags('{{env::path}}').$objModel->path;

		$GLOBALS['TL_HEAD'][] = '<meta property="og:type" content="product" />';
		$GLOBALS['TL_HEAD'][] = '<meta property="og:title" content="'.$objProduct->title.'" />';
		$GLOBALS['TL_HEAD'][] = '<meta property="og:url" content="'.$ogTagsURL.'" />';
		$GLOBALS['TL_HEAD'][] = '<meta property="og:image" content="'.$ogTagsImage.'" />';

		if ( $this->related_show)
		{
			$objProduct->related = deserialize($objProduct->related);

			if ($objProduct->related) {

				$objProducts = ProductModel::findPublishedByIds($objProduct->related);
	
				$this->Template->relateds = $this->parseRelateds($objProducts);
			}
		}

			$bundles = \System::getContainer()->getParameter('kernel.bundles');

		// HOOK: comments extension required
		if ($objProduct->noComments || !isset($bundles['ContaoCommentsBundle']))
		{
			$this->Template->allowComments = false;

			return;
		}

		/** @var ProductCatalogModel $objCatalog */
		$objCatalog = $objProduct->getRelated('pid');
		$this->Template->allowComments = $objCatalog->allowComments;

		// Comments are not allowed
		if (!$objCatalog->allowComments)
		{
			return;
		}

		// Adjust the comments headline level
		$intHl = min((int) str_replace('h', '', $this->hl), 5);
		$this->Template->hlc = 'h' . ($intHl + 1);

		$this->import(\Comments::class, 'Comments');
		$arrNotifies = array();

		// Notify the system administrator
		if ($objCatalog->notify != 'notify_author')
		{
			$arrNotifies[] = $GLOBALS['TL_ADMIN_EMAIL'];
		}

		// Notify the author
		if ($objCatalog->notify != 'notify_admin')
		{
			/** @var UserModel $objAuthor */
			if (($objAuthor = $objProduct->getRelated('author')) instanceof UserModel && $objAuthor->email != '')
			{
				$arrNotifies[] = $objAuthor->email;
			}
		}

		$objConfig = new \stdClass();

		$objConfig->perPage = $objCatalog->perPage;
		$objConfig->order = $objCatalog->sortOrder;
		$objConfig->template = $this->com_template;
		$objConfig->requireLogin = $objCatalog->requireLogin;
		$objConfig->disableCaptcha = $objCatalog->disableCaptcha;
		$objConfig->bbcode = $objCatalog->bbcode;
		$objConfig->moderate = $objCatalog->moderate;

		$this->Comments->addCommentsToTemplate($this->Template, $objConfig, 'tl_products', $objProduct->id, $arrNotifies);

	}
}
