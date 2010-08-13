<?php
declare(ENCODING = 'utf-8');
namespace F3\TypoScript;

/*                                                                        *
 * This script belongs to the FLOW3 package "TypoScript".                 *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License as published by the Free   *
 * Software Foundation, either version 3 of the License, or (at your      *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General      *
 * Public License for more details.                                       *
 *                                                                        *
 * You should have received a copy of the GNU General Public License      *
 * along with the script.                                                 *
 * If not, see http://www.gnu.org/licenses/gpl.html                       *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * Common class for TypoScript Content Objects
 *
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
abstract class AbstractContentObject extends \F3\TypoScript\AbstractObject implements \F3\TypoScript\ContentObjectInterface {

	/**
	 * A valid source for a TypoScript Template object which should be the default
	 * this TypoScript object. Should be overriden by the actual TS object implementation.
	 *
	 * @var string
	 */
	protected $templateSource;

	/**
	 * @var \F3\TYPO3\TypoScript\Template
	 */
	protected $template;

	/**
	 * Names of the properties of this TypoScript which should be available in
	 * this TS object's template while rendering it.
	 *
	 * Note: Make sure that a getter method for the respective property exists.
	 *
	 * @var array
	 */
	protected $presentationModelPropertyNames = array();

	/**
	 * The rendering context as passed to render()
	 * 
	 * @transient
	 * @var \F3\TypoScript\RenderingContext
	 */
	protected $renderingContext;

	/**
	 * Injects a fresh template
	 *
	 * @param \F3\TYPO3\TypoScript\Template $template
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function injectTemplate(\F3\TYPO3\TypoScript\Template $template) {
		$this->template = $template;
		$this->template->setSource($this->templateSource);
	}

	/**
	 * Sets the rendering context
	 * 
	 * @param \F3\Fluid\Core\Rendering\RenderingContextInterface $renderingContext
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function setRenderingContext(\F3\Fluid\Core\Rendering\RenderingContextInterface $renderingContext) {
		if (!$renderingContext instanceof \F3\TypoScript\RenderingContext) {
			throw new \InvalidArgumentException('AbstractContentObject only supports \F3\TypoScript\RenderingContext as a rendering context.', 1277825291);
		}
		$this->renderingContext = $renderingContext;
	}

	/**
	 * Overrides the template
	 *
	 * Note: You rarely want to override the actual template object - that's only
	 *       the case if you want to use an alternative templating engine.
	 *       If all you want is a Fluid template, then just set the templateSource
	 *       instead of setting the template object.
	 *
	 * @param \F3\TYPO3\TypoScript\Template $template
	 * @return void
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function setTemplate(\F3\TYPO3\TypoScript\Template $template) {
		$this->template = $template;
	}

	/**
	 * Returns the page template object
	 *
	 * @return \F3\TYPO3\TypoScript\Template
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function getTemplate() {
		return $this->template;
	}

	/**
	 * Returns the rendered content of this content object
	 *
	 * @todo Discuss how to expose the domain model for identity to the view
	 *
	 * @return string The rendered content as a string - usually (X)HTML, XML or just plain text
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function render() {
		$this->template->setRenderingContext($this->renderingContext);

		foreach ($this->presentationModelPropertyNames as $propertyName) {
			$this->template->assign($propertyName, $this->getPropertyProcessingProxy($propertyName));
		}
		if ($this->model !== NULL) {
			$this->template->assign('domainModel', $this->model);
		}

		if (isset($this->propertyProcessorChains['_root'])) {
			return $this->propertyProcessorChains['_root']->process($this->template->render());
		} else {
			return $this->template->render();
		}
	}

	/**
	 * Casts this TypoScript Object to a string by invoking the render() method.
	 *
	 * @return string
	 * @author Robert Lemke <robert@typo3.org>
	 */
	public function __toString() {
		try {
			return $this->render();
		} catch (\Exception $exception) {
			return $exception->__toString();
     	}
	}


}
?>