<?php
namespace TYPO3\TypoScript\Core;

/*                                                                        *
 * This script belongs to the FLOW3 package "TypoScript".                 *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU General Public License, either version 3 of the   *
 * License, or (at your option) any later version.                        *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

use TYPO3\FLOW3\Annotations as FLOW3;

use TYPO3\FLOW3\Utility\Arrays;
use TYPO3\FLOW3\Reflection\ObjectAccess;

/**
 * TypoScript Runtime
 *
 */
class Runtime {

	/**
	 * Internal constants defining how evaluateInternal should work in case of an error.
	 */
	const BEHAVIOR_EXCEPTION = 'Exception';
	const BEHAVIOR_RETURNNULL = 'NULL';

	/**
	 * @var \TYPO3\Eel\CompilingEvaluator
	 * @FLOW3\Inject
	 */
	protected $eelEvaluator;

	/**
	 * @var \TYPO3\TypoScript\Core\ProcessorEvaluator
	 * @FLOW3\Inject
	 */
	protected $processorEvaluator;

	// Contains list of contexts
	protected $renderingStack = array();

	/**
	 * @var array
	 */
	protected $typoScriptConfiguration;

	/**
	 * @var \TYPO3\FLOW3\MVC\Controller\ControllerContext
	 */
	protected $controllerContext;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * Constructor for the TypoScript Runtime
	 * @param array $typoScriptConfiguration
	 * @param \TYPO3\FLOW3\MVC\Controller\ControllerContext $controllerContext
	 */
	public function __construct(array $typoScriptConfiguration, \TYPO3\FLOW3\MVC\Controller\ControllerContext $controllerContext) {
		$this->typoScriptConfiguration = $typoScriptConfiguration;
		$this->controllerContext = $controllerContext;
	}

	/**
	 * @param array
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->settings = $settings;
	}

	/**
	 * Push a new context object to the rendering stack
	 *
	 * @param mixed $context
	 */
	public function pushContext($context) {
		$this->renderingStack[] = $context;
	}

	/**
	 * Remove the topmost context object and return it
	 *
	 * @return mixed the topmost context object
	 */
	public function popContext() {
		return array_pop($this->renderingStack);
	}

	/**
	 * Get the current context object
	 *
	 * @return mixed the current context object
	 */
	public function getCurrentContext() {
		return $this->renderingStack[count($this->renderingStack) - 1];
	}

	/**
	 * Evaluate an absolute TypoScript path and return the result
	 *
	 * @param string $typoScriptPath
	 * @return mixed the result of the evaluation, can be a string but also other data types
	 */
	public function evaluate($typoScriptPath) {
		return $this->evaluateInternal($typoScriptPath, self::BEHAVIOR_RETURNNULL);
	}

	/**
	 * Render an absolute TypoScript path and return the result.
	 *
	 * Compared to $this->evaluate, this adds some more comments helpful for debugging.
	 *
	 * @param string $typoScriptPath
	 * @return string
	 */
	public function render($typoScriptPath) {
		try {
			$output = '';
			$output = sprintf(chr(10) . '<!-- Beginning to render TS path "%s" (Context: %s) -->', $typoScriptPath, $this->getCurrentContext());
			$output .= $this->evaluateInternal($typoScriptPath, self::BEHAVIOR_EXCEPTION);
			$output .= sprintf(chr(10) . '<!-- End to render TS path "%s" (Context: %s) -->', $typoScriptPath, $this->getCurrentContext());
			return $output;
		} catch (\Exception $e) {
			if ($this->settings['catchRuntimeExceptions'] === TRUE) {
				return '<!-- Exception while rendering ' . htmlspecialchars($typoScriptPath) . ' : ' . $e->getMessage() . ' -->';
			} else {
				throw $e;
			}
		}
	}

	/**
	 * Internal evaluation method of absolute $typoScriptpath
	 *
	 * @param string $typoScriptPath
	 * @param string $behaviorIfPathNotFound one of BEHAVIOR_EXCEPTION or BEHAVIOR_RETURNNULL
	 * @return mixed
	 */
	protected function evaluateInternal($typoScriptPath, $behaviorIfPathNotFound) {
		$typoScriptConfiguration = $this->getConfigurationForPath($typoScriptPath);

		if (!isset($typoScriptConfiguration['implementationClassName']) || !isset($typoScriptConfiguration['__objectType'])) {
			if ($behaviorIfPathNotFound === self::BEHAVIOR_EXCEPTION) {
				throw new \TYPO3\TypoScript\Exception('Element at path ' . htmlspecialchars($typoScriptPath) . ' could not be rendered because ts object type or implementation class name was not found.', 1332493990);
			} else {
				return NULL;
			}
		}
		$typoScriptObjectType = $typoScriptConfiguration['__objectType'];

		$tsObjectClassName = $typoScriptConfiguration['implementationClassName'];

		if (!preg_match('#<[^>]*>$#', $typoScriptPath)) {
				// Only add typoscript object type to last path part if not already set
			$typoScriptPath .= '<' . $typoScriptObjectType . '>';
		}
		$tsObject = new $tsObjectClassName($this, $typoScriptPath, $typoScriptObjectType);
		$this->setOptionsOnTsObject($tsObject, $typoScriptConfiguration);

		$output = $tsObject->evaluate($this->getCurrentContext());
		return $this->evaluateProcessor('__all', $tsObject, $output);
	}

	/**
	 * Get the TypoScript Configuration for the given TypoScript path
	 *
	 * @param string $typoScriptPath
	 * @return array
	 */
	protected function getConfigurationForPath($typoScriptPath) {
		$pathParts = explode('/', $typoScriptPath);

		$configuration = $this->typoScriptConfiguration;

		$pathUntilNow = '';
		if (isset($configuration['__prototypes'])) {
			$currentPrototypeDefinitions = $configuration['__prototypes'];
		} else {
			$currentPrototypeDefinitions = array();
		}

		foreach ($pathParts as $pathPart) {
			$pathUntilNow .= '/' . $pathPart;
			if (preg_match('#^([^<]*)(<(.*?)>)?$#', $pathPart, $matches)) {
				$currentPathSegment = $matches[1];

				if (isset($configuration[$currentPathSegment])) {
					$configuration = $configuration[$currentPathSegment];
				} else {
					$configuration = array();
				}

				if (isset($configuration['__prototypes'])) {
					$currentPrototypeDefinitions = Arrays::arrayMergeRecursiveOverrule($currentPrototypeDefinitions, $configuration['__prototypes']);
				}

				if (isset($matches[3])) {
					$currentPathSegmentType = $matches[3];
				} elseif (isset($configuration['__objectType'])) {
					$currentPathSegmentType = $configuration['__objectType'];
				} else {
					$currentPathSegmentType = NULL;
				}

				if ($currentPathSegmentType !== NULL) {
					if (isset($currentPrototypeDefinitions[$currentPathSegmentType])) {
						$configuration = Arrays::arrayMergeRecursiveOverrule($currentPrototypeDefinitions[$currentPathSegmentType], $configuration);
						if (isset($currentPrototypeDefinitions[$currentPathSegmentType]['__prototypes'])) {
								// this here handles the case of prototype("foo").prototype("baz")
							$currentPrototypeDefinitions = Arrays::arrayMergeRecursiveOverrule($currentPrototypeDefinitions, $currentPrototypeDefinitions[$currentPathSegmentType]['__prototypes']);
						}
					}

					$configuration['__objectType'] = $currentPathSegmentType;
				}

			} else {
				throw new TYPO3\TypoScript\Exception('Path Part ' . $pathPart . ' not well-formed', 1332494645);
			}
		}

		return $configuration;
	}

	/**
	 * Set options on the given TypoScript obect
	 * @param \TYPO3\TypoScript\TypoScriptObjects\AbstractTsObject $tsObject
	 * @param string $typoScriptConfiguration
	 */
	protected function setOptionsOnTsObject(\TYPO3\TypoScript\TypoScriptObjects\AbstractTsObject $tsObject, array $typoScriptConfiguration) {
		foreach ($typoScriptConfiguration as $key => $value) {
			if ($key === 'implementationClassName') continue;
			if ($key === '__processors') $tsObject->setInternalProcessors($value);
			# TODO THAT'S VERY UGLY!!
			if ($key[0] === '_' && $key[1] === '_') continue;
			ObjectAccess::setProperty($tsObject, $key, $value);
		}
	}

	/**
	 * Evaluate the processors for $variableName
	 *
	 * @param string $variableName
	 * @param \TYPO3\TypoScript\TypoScriptObjects\AbstractTsObject $tsObject
	 * @param mixed $value
	 * @return mixed
	 */
	public function evaluateProcessor($variableName, \TYPO3\TypoScript\TypoScriptObjects\AbstractTsObject $tsObject, $value) {
		if (is_array($value) && isset($value['__eelExpression'])) {
			$context = new \TYPO3\Eel\Context(array(
				'context' => new \TYPO3\Eel\FlowQuery\FlowQuery(array($this->getCurrentContext())),
				'this' => $tsObject
			));
			$value = $this->eelEvaluator->evaluate($value['__eelExpression'], $context);
		}
		return $this->processorEvaluator->evaluateProcessor($tsObject->getInternalProcessors(), $variableName, $value);
	}

	/**
	 * @return \TYPO3\FLOW3\MVC\Controller\ControllerContext
	 */
	public function getControllerContext() {
		return $this->controllerContext;
	}
}
?>