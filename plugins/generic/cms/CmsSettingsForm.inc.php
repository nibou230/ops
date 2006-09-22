<?php

/**
 * CmsSettingsForm.inc.php
 *
 * Copyright (c) 2003-2005 The Public Knowledge Project
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @package plugins
 *
 * Form for journal managers to modify Cms Plugin content
 * 
 * $Id$
 */

import('form.Form');

class CmsSettingsForm extends Form {
	/** @var $journalId int */
	var $journalId;

	/** @var $plugin object */
	var $plugin;

	/** $var content manager object */
	var $contentManager;
	
	/** $var $errors string */
	var $errors;
	
	/**
	 * Constructor
	 * @param $journalId int
	 */
	function CmsSettingsForm(&$plugin, $journalId) {

		parent::Form($plugin->getTemplatePath() . 'settingsForm.tpl');
	
		$this->journalId = $journalId;
		$this->plugin =& $plugin;

		$plugin->import('ContentManager');	
		$this->contentManager =& new ContentManager();

		$this->addCheck(new FormValidatorCustom($this, 'content', 'required', null, array(&$this, 'checkAndFixContent')));

	}
	
	/*
	 * Check and fix content as necessary.
	 * $content string - submitted content
	 * 
	 * return true in all cases so that no error message from the FormValidatorCustom is showen
	 * but since addError is set in some cases, the FormValidatorCustom will still fail on validate
	 */
	function checkAndFixContent ( $content ) {

		$content = trim($content);
		/* force the content to start with a header */
		if ( strlen($content) > 0 &&
			 substr($content, 0, 3) != '<h1' && 
			 substr($content, 0, 3) != '<h2' &&  
			 substr($content, 0, 3) != '<h3'  )  {
			$this->setData('content', trim($content));
			$this->addError('content', 'plugins.generic.cms.error.muststartwithheader');
			return true;
		}
		
		/* parse out the content */
		preg_match_all('/<h([1-3])>(.*)<\/h[1-3]>/U', $content, $headMatches);
		preg_match_all('/<\/h([1-3])>(.*)<(h[1-3]|\/body)>/U', $content.'</body>', $contentMatches);

		/* there should be the same number of headings as content */
		if ( count($headMatches[0]) != count($contentMatches[0]) ) {
			$this->setData('content', $content);
			$this->addError('content', 'plugins.generic.cms.error.musthavecontent');
			return true;
		} 
		
		/* force heading levels to go up by 1 only (e.g. no h1 to h3) */
		$prev = 1;
		for ( $i = 0; $i < count($headMatches[0]); $i++ ) {
			// make sure no heading levels are skipped
			if ( $headMatches[1][$i] > $prev + 1 )
				$headMatches[1][$i] = $prev + 1;			
			$prev = $headMatches[1][$i];
			
			/* eliminate <br> and &nbsp; from inside the headings */
			$headMatches[2][$i] = preg_replace('/<.*>/','', $headMatches[2][$i]);
			$headMatches[2][$i] = str_replace('&nbsp;', ' ', $headMatches[2][$i]);
			$headMatches[2][$i] = trim($headMatches[2][$i]);
			
			if ( str_replace(' ', '', $headMatches[2][$i]) == '' ) {
				$this->setData('content', $content);
				$this->addError('content', 'plugins.generic.cms.error.headingcannotbeempty');
				return true;				
			}			
		}
		
		/* make sure that the content is non empty (at least one tag -- hence the '<') */
		for ( $i = 0; $i < count($contentMatches[0]); $i++ ) {		
			if ( strpos($contentMatches[2][$i], '<' ) === false ) {
				$this->setData('content', $content);
				$this->addError('content', 'plugins.generic.cms.error.musthavecontent');
				return true;
			}
		}
		
		/* rewrite the content with any changes that may have been made above */
		$content = '';
		for ( $i = 0; $i < count($headMatches[0]); $i++ ) {
			$content .= "<h".$headMatches[1][$i].">".$headMatches[2][$i]."</h".$headMatches[1][$i].">";
			$content .= $contentMatches[2][$i];
		}

		$this->setData('content', $content);
		return true;
	}
	
	/**
	 * Initialize form data from current group group.
	 */
	function initData( $args ) {
		$journalId = $this->journalId;
		$plugin =& $this->plugin;
		$contentManager =& $this->contentManager;

		$templateMgr = &TemplateManager::getManager();

		$headings = array();
		$content = array();
	
		// figure out the current page 
		if (count($args) > 0 && $args[0] != '') {
			$current = $args[0];
		} else {
			$current = $contentManager->defaultHeading[1];
		}
		
		// go through the file 
		$contentManager->parseContents(&$headings, &$content, $current);

		// grab the content and the heading
		if ( isset($current) && count($content) > 0 ) {
			$currentContent = $content[$current];
			// have to find the actual heading based on the webSafe equivalent
			foreach ( $headings as $heading ) {
				if ( $heading[1] == $current ) {
					$currentHeading = "<h".$heading[0].">".$heading[2]."</h".$heading[0].">";
					break;
				}
			}
		// if no argument was passed
		} else {
			$currentContent = "";		
			$currentHeading = "";
		}

		// Enable TinyMCE with specific params
		$additionalHeadData = $templateMgr->get_template_vars('additionalHeadData');

		$journal =& Request::getJournal();

		import('file.JournalFileManager');
		$journalFileManager =& new JournalFileManager($journal);
		
		$tinyMCE_script = '
		<script language="javascript" type="text/javascript" src="'.Request::getBaseUrl().'/'.TINYMCE_JS_PATH.'/tiny_mce.js"></script>
		<script language="javascript" type="text/javascript">
			tinyMCE.init({
			mode : "textareas",
			plugins: "save, table, advimage",
			relative_urls : false, 		
			document_base_url : "'.Request::url('', 'manager', 'files').'/content/", 
			theme : "advanced",
			theme_advanced_layout_manager : "SimpleLayout",
			theme_advanced_buttons1 : "save, formatselect, bold, italic, underline, justifyleft, justifycenter, justifyright, justifyfull, bullist, numlist, outdent, indent, code",
			theme_advanced_buttons2 : "image, link, unlink",
			theme_advanced_buttons3 : "tablecontrols"
			});
		</script>';

		$templateMgr->assign('additionalHeadData', $additionalHeadData."\n".$tinyMCE_script);
		$templateMgr->assign('cmsPluginToc', $headings);		
		$templateMgr->assign('currentHeading', $currentHeading );
		$templateMgr->assign('currentContent', $currentContent );
		$templateMgr->assign('current', $current );
		$templateMgr->assign('cmsPluginEdit', true);
	}
	
	/**
	 * Assign form data to user-submitted data.
	 */
	function readInputData() {
		$this->readUserVars(array('content', 'current'));
	}
	
	/**
	 * Save page - write to content file. 
	 */	 
	function save() {
		$plugin =& $this->plugin;
		$journalId = $this->journalId;
		$contentManager =& $this->contentManager;		
		
		$journal = &Request::getJournal();
		
		$content = $this->getData('content');
		$current = $this->getData('current');

		$contentManager->insertContent($content, &$current);
		$this->setData('current', $current);	
		
		$h = array();
		$c = array();
		// no current value because we don't want anything selected
		$contentManager->parseContents( &$h, &$c );
		// this sets the table of contents with nothing selected
		$plugin->updateSetting($journalId, 'toc', $h); 		
	}
	
	/**
	 * Clean up and reset ToC
	 * execute is done on submit
	 */
	function execute() {
		$plugin =& $this->plugin;
		$journalId = $this->journalId;
		$contentManager =& $this->contentManager;		

		$h = array();
		$c = array();
				
		// no current value because we don't want anything selected
		$contentManager->parseContents( &$h, &$c );
		
		// this sets the table of contents with nothing selected
		$plugin->updateSetting($journalId, 'toc', $h); 
	}
		
}
?>
