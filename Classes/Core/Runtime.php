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
 * TypoScript Rendering Process
 * ============================
 *
 * During rendering, all TypoScript objects form a tree.
 *
 * When a TypoScript object at a certain $typoScriptPath is invoked, it has
 * access to all variables stored in the $context (which is an array).
 *
 * The TypoScript object can then add or replace variables to this context using pushContext()
 * or pushContextArray(), before rendering sub-TypoScript objects. After rendering
 * these, it must call popContext() to reset the context to the last state.
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

	/**
	 * Contains list of contexts
	 * @var array
	 */
	protected $renderingStack = array();

	/**
	 * @var array
	 */
	protected $typoScriptConfiguration;

	/**
	 * @var \TYPO3\FLOW3\Mvc\Controller\ControllerContext
	 */
	protected $controllerContext;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * Constructor for the TypoScript Runtime
	 * @param array $typoScriptConfiguration
	 * @param \TYPO3\FLOW3\Mvc\Controller\ControllerContext $controllerContext
	 */
	public function __construct(array $typoScriptConfiguration, \TYPO3\FLOW3\Mvc\Controller\ControllerContext $controllerContext) {
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
	 * Completely replace the context array with the new $contextArray.
	 *
	 * Purely internal method, should not be called outside of TYPO3.TypoScript.
	 *
	 * @param array $contextArray
	 * @return void
	 */
	public function pushContextArray(array $contextArray) {
		$this->renderingStack[] = $contextArray;
	}

	/**
	 * Push a new context object to the rendering stack
	 *
	 * @param string $key the key inside the context
	 * @param mixed $context
	 * @return void
	 */
	public function pushContext($key, $context) {
		$newContext = $this->getCurrentContext();
		$newContext[$key] = $context;
		$this->renderingStack[] = $newContext;
	}

	/**
	 * Remove the topmost context objects and return them
	 *
	 * @return array the topmost context objects as associative array
	 */
	public function popContext() {
		return array_pop($this->renderingStack);
	}

	/**
	 * Get the current context object
	 *
	 * @return array the array of current context objects
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
			$output = $this->evaluateInternal($typoScriptPath, self::BEHAVIOR_EXCEPTION);

			if (isset($this->settings['debugMode']) && $this->settings['debugMode'] === TRUE) {
				$output = sprintf('%1$s<!-- Beginning to render TS path "%2$s" (Context: %3$s) -->%4$s%1$s<!-- End to render TS path "%2$s" (Context: %3$s) -->',
					chr(10),
					$typoScriptPath,
					implode(', ', array_keys($this->getCurrentContext())),
					$output
				);
			}
			if (is_string($output)) {
				$output = trim($output);
			}
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
	 * Determine if the given TypoScriptPath is renderable, which means it exists and has an implementation.
	 *
	 * @param string $typoScriptPath
	 * @return boolean
	 */
	public function canRender($typoScriptPath) {
		$typoScriptConfiguration = $this->getConfigurationForPath($typoScriptPath);

		return $this->canRenderWithConfiguration($typoScriptConfiguration);
	}

	/**
	 * Internal evaluation if given configuration is renderable.
	 *
	 * @param array $typoScriptConfiguration
	 * @return boolean
	 */
	protected function canRenderWithConfiguration(array $typoScriptConfiguration) {
			// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
		if (!(isset($typoScriptConfiguration['__meta']['class']) || isset($typoScriptConfiguration['implementationClassName'])) || !isset($typoScriptConfiguration['__objectType'])) {
			return FALSE;
		}

		return TRUE;
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

		if (!$this->canRenderWithConfiguration($typoScriptConfiguration)) {
			if ($behaviorIfPathNotFound === self::BEHAVIOR_EXCEPTION) {
				if (!isset($typoScriptConfiguration['__objectType'])) {
					throw new \TYPO3\TypoScript\Exception('Element at typoscript path "' . $typoScriptPath . '" could not be rendered because ts object type was not found.', 1332493990);
				} else {
					throw new \TYPO3\TypoScript\Exception('Element at typoscript path "' . $typoScriptPath . '" could not be rendered because implementation class name for "' . $typoScriptConfiguration['__objectType'] . '" was not found.', 1332493995);
				}
			} else {
				return NULL;
			}
		}
		$typoScriptObjectType = $typoScriptConfiguration['__objectType'];

			// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
		$tsObjectClassName = isset($typoScriptConfiguration['__meta']['class']) ? $typoScriptConfiguration['__meta']['class'] : $typoScriptConfiguration['implementationClassName'];

		if (!preg_match('#<[^>]*>$#', $typoScriptPath)) {
				// Only add typoscript object type to last path part if not already set
			$typoScriptPath .= '<' . $typoScriptObjectType . '>';
		}
		$tsObject = new $tsObjectClassName($this, $typoScriptPath, $typoScriptObjectType);
		$this->setOptionsOnTsObject($tsObject, $typoScriptConfiguration);

			// modify context if @override is specified
		if (isset($typoScriptConfiguration['__meta']['override'])) {
			$contextArray = $this->getCurrentContext();
			foreach ($typoScriptConfiguration['__meta']['override'] as $overrideKey => $overrideValue) {
				$contextArray[$overrideKey] = $this->evaluateProcessor('@override.' . $overrideKey, $tsObject, $overrideValue);
			}
			$this->pushContextArray($contextArray);
		}

		$output = $tsObject->evaluate();

		if (isset($typoScriptConfiguration['__meta']['override'])) {
			$this->popContext();
		}
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
				throw new \TYPO3\TypoScript\Exception('Path Part ' . $pathPart . ' not well-formed', 1332494645);
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
				// DEPRECATED implementationClassName is deprecated since Sprint 10, use @class instead
			if ($key === '@class' || $key === 'implementationClassName') {
					// The @class property is already handled by the TypoScript runtime
				continue;
			}
			if ($key === '__processors') {
				$tsObject->setInternalProcessors($value);
			}

				// skip keys which start with __, as they are purely internal.
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

			$contextVariables = $this->getCurrentContext();
			if (!isset($contextVariables['context']) && isset($contextVariables['node'])) {
					// DEPRECATED since sprint release 10; should be removed lateron.
				$contextVariables['context'] = new \TYPO3\Eel\FlowQuery\FlowQuery(array($contextVariables['node']));
			}
			if (isset($contextVariables['q'])) {
				throw new \TYPO3\TypoScript\Exception('Context variable "q" not allowed, as it is already reserved for FlowQuery use.', 1344325040);
			}
			$contextVariables['q'] = function($element) {
				return new \TYPO3\Eel\FlowQuery\FlowQuery(array($element));
			};
			if (isset($contextVariables['this'])) {
				throw new \TYPO3\TypoScript\Exception('Context variable "this" not allowed, as it is already reserved for a pointer to the current TypoScript object.', 1344325044);
			}
			$contextVariables['this'] = $tsObject;

			$context = new \TYPO3\Eel\Context($contextVariables);
			$value = $this->eelEvaluator->evaluate($value['__eelExpression'], $context);
		}
		return $this->processorEvaluator->evaluateProcessor($tsObject->getInternalProcessors(), $variableName, $value);
	}

	/**
	 * @return \TYPO3\FLOW3\Mvc\Controller\ControllerContext
	 */
	public function getControllerContext() {
		return $this->controllerContext;
	}
}
?>