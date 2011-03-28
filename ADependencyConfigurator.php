<?php
use InvalidArgumentException, RuntimeException, BadMethodCallException;
use ReflectionFunction;
use Closure;

/**
 * A helper class for dependency injection using an array of values and getter callbacks in the constructor.
 */
abstract class ADependencyConfigurator implements IToArray {
	/**
	 * The settings that can be specified at construction time, and their expected type. By default, these settings
	 * are gettable and settable after construction time, by using $this->myThing or $this->getMyThing() and setMyThing().
	 *
	 * If they are callbacks, suffix the name with "Getter" and specify the expected return type after 'callback '.
	 *
	 * This is an array of
	 *     $settingName => array(
	 *         'isRequired' => boolean, // If true, this setting MUST be specified at construction time.
	 *         'isGettable' => boolean, // Optional; defaults to true, a.k.a. "this setting/property is gettable when accessing $object->bla or $object->getBla()".
	 *         'isSettable' => boolean, // Optional; defaults to true, a.k.a. "this setting/property is settable when using $object->bla or $object->setBla($bla)".
	 *         'isExportable' => boolean, // Optional; defaults to isGettable, a.k.a. "this setting/property is exported when calling $object->toArray()".
	 *         'type' => ("boolean"|"integer"|"double"|"string"|"array") or a fully-qualified interface or class name,
	 *         'default' => mixed // Optional; for non-required properties, this specifies the default value.
	 *     )
	 */
	protected $constructorSettings = array();
	/**
	 * The settings for the current instance, as an array of
	 *     $settingName => array(
	 *         'isRequired' => boolean,
	 *         'isGettable' => boolean,
	 *         'isSettable' => boolean,
	 *         'isExportable' => boolean,
	 *         'type' => $settingType,
	 *         'value' => $currentValue,
	 *     )
	 */
	protected $settings = array();
	/**
	 * The cached results of the 'fooGetter' callbacks that are called by $this->__get('foo') when accessing $this->foo or $this->getFoo().
	 */
	protected $cachedGetterResults = array();

	/**
	 * Create a new instance with the given settings.
	 *
	 * @param array $settings The settings for the new instance. See $this->constructorSettings.
	 */
	public function __construct(array $settings = array()) {
		foreach ($this->constructorSettings as $settingName => $settingData) {
			if (!isset($settingData['isRequired'])) {
				throw new RuntimeException(get_class($this) . ": The \"isRequired\" specification for setting \"$settingName\" is missing.");
			}
			$isSettingRequired = $settingData['isRequired'];
			if (!isset($settingData['type'])) {
				throw new RuntimeException(get_class($this) . ": The \"type\" specification for setting \"$settingName\" is missing.");
			}
			$settingType = $settingData['type'];
			if (!isset($settings[$settingName])) {
				if ($isSettingRequired) {
					throw new InvalidArgumentException(get_class($this) . ": The required setting \"$settingName\" is missing. (Expected \"$settingType\".)");
				}
				continue;
			}
		}
		foreach ($settings as $name => $value) {
			$this->__set($name, $value);
		}
	}

	/**
	 * Set one of the settings.
	 *
	 * @param string $name
	 * @param mixed $value
	 */
	public function __set($name, $value) {
		// If the property was not known, bail out.
		if (!isset($this->constructorSettings[$name])) {
			return; // dont stop the music;
		}

		$settingName = $name;
		$settingData = $this->constructorSettings[$settingName];
		$settingType = $settingData['type'];
		// Default to "No, it is not required".
		$isSettingRequired = isset($settingData['isRequired']) && $settingData['isRequired'];
		$settingRequirement = $isSettingRequired
			? 'required'
			: 'optional';
		// Default to "Yes, it is gettable.
		$isSettingGettable = !isset($settingData['isGettable']) || $settingData['isGettable'];
		// Default to "Yes, it is settable."
		$isSettingSettable = !isset($settingData['isSettable']) || $settingData['isSettable'];
		// Default to "Yes, it is exportable if it is gettable."
		$isSettingExportable = isset($settingData['isExportable'])
			? $settingData['isExportable']
			: $isSettingGettable;

		// If the setting is not settable, check to see if we are called internally (i.e., through the constructor or from this class or one of its subclasses).
		if (!$isSettingSettable) {
			$isComingFromConstructor = false;
			$stack = debug_backtrace();
			foreach ($stack as $i => $stackEntry) {
				if (isset($stackEntry['function']) && $stackEntry['function'] === '__construct' && isset($stackEntry['object']) && $stackEntry['object'] === $this) {
					$isComingFromConstructor = true;
					break;
				}
				if (!isset($stackEntry['object']) || $stackEntry['object'] !== $this) {
					break;
				}
			}
			if (!$isComingFromConstructor) {
				$isUsingSetter = empty($stack[$i - 1]['function']) || $stack[$i - 1]['function'] === '__set' || $stack[$i - 1]['function'] === 'set' . ucfirst($name);
				$isCalledExternally = empty($stack[$i]['object']) || $stack[$i]['object'] !== $this;

				if ($isUsingSetter && $isCalledExternally) {
					throw new InvalidArgumentException(get_class($this) . ": The setting \"$settingName\" is not settable.");
				}
			}
		}

		// Check the specified value.
		$settingChecker = function ($expectedType, $currentValue) use (&$settingChecker) {
			list($expectedType) = explode(' ', $expectedType);
			// From the docs: "Never use gettype() to test for a certain type, since the returned string may be subject to change in a future version."
			// If that happens, we can catch it in this class.
			$currentType = is_object($currentValue)
				? get_class($currentValue)
				: gettype($currentValue);
			if ($expectedType === 'callback') {
				$isCurrentValueOk = is_callable($currentValue);
			} elseif ($expectedType === 'stdClass' && is_array($currentValue)) {
				$currentValue = (object)$currentValue;
				$isCurrentValueOk = true;
			} elseif ($expectedType !== 'array' && $currentType === 'array' && !in_array($expectedType, array('boolean', 'integer', 'double', 'string', 'resource', 'NULL', 'unknown type'))) {
				if (is_subclass_of($expectedType, __CLASS__)) {
					if (isset($currentValue['_className'])) {
						$expectedType = $currentValue['_className'];
						unset($currentValue['_className']);
					}
					return $settingChecker($expectedType, new $expectedType($currentValue));
				}
				// If we ever do create a IFromArray, this would be the place to handle that.
				// I.e., elseif implements IFromArray then return $settingChecker($expectedType, $class::fromArray($currentValue);
				$isCurrentValueOk = false;
			} else {
				$isCurrentValueOk = is_object($currentValue)
					? is_a($currentValue, $expectedType)
					: $currentType === $expectedType;
			}
			return array($currentValue, $currentType, $isCurrentValueOk);
		};
		list($resultValue, $resultType, $isResultValueOk) = $settingChecker($settingType, $value);
		if ($value === null) {
			// dont set anything but it is valid
		} elseif (!$isResultValueOk) {
			throw new InvalidArgumentException(get_class($this) . ": The $settingRequirement setting \"$settingName\" was not \"$settingType\", but \"$resultType\".");
		} else {
			// Finally, store the value.
			$this->settings[$settingName] = array(
				'isRequired' => $isSettingRequired,
				'isGettable' => $isSettingGettable,
				'isSettable' => $isSettingSettable,
				'isExportable' => $isSettingExportable,
				'type' => $settingType,
				'value' => $resultValue
			);
		}
	}

	/**
	 * Get one of the settings. If the property was a getter, call it. This enables lazily loading "expensive" properties.
	 *
	 * @param string $name
	 * @return mixed
	 */
	public function __get($name) {
		if (isset($this->settings[$name])) {
			// If the setting is not gettable, check to see if we are called internally (i.e., through the constructor or from this class or one of its subclasses).
			if (!$this->settings[$name]['isGettable']) {
				$isComingFromConstructor = false;
				$stack = debug_backtrace();
				foreach ($stack as $i => $stackEntry) {
					if (isset($stackEntry['function']) && $stackEntry['function'] === '__construct' && isset($stackEntry['object']) && $stackEntry['object'] === $this) {
						$isComingFromConstructor = true;
						break;
					}
					if (!isset($stackEntry['object']) || $stackEntry['object'] !== $this) {
						break;
					}
				}
				if (!$isComingFromConstructor) {
					$isUsingGetter = empty($stack[$i - 1]['function']) || $stack[$i - 1]['function'] === '__get' || $stack[$i - 1]['function'] === 'get' . ucfirst($name);
					$isCalledExternally = empty($stack[$i]['object']) || $stack[$i]['object'] !== $this;

					if ($isUsingGetter && $isCalledExternally) {
						throw new InvalidArgumentException(get_class($this) . ": The setting \"$name\" is not gettable.");
					}
				}
			}
			return $this->settings[$name]['value'];
		} elseif (isset($this->constructorSettings["{$name}Getter"])) {
			// Cache the result of getter callbacks when called as a property.
			if (!array_key_exists($name, $this->cachedGetterResults)) {
				$this->cachedGetterResults[$name] = $this->__call('get' . ucfirst($name), array());
			}
			return $this->cachedGetterResults[$name];
		} elseif (isset($this->constructorSettings[$name]) && empty($this->constructorSettings[$name]['isRequired'])) {
			// Optional settings that have not been specified return their default value, if any.
			if (isset($this->constructorSettings[$name]['default'])) {
				return $this->constructorSettings[$name]['default'];
			}
			// Optional settings that have not been specified return null by default.
			return null;
		}
		throw new InvalidArgumentException(get_class($this) . ": There is no setting called \"$name\".");
	}

	public function __isset($name) {
		return isset($this->settings[$name]);
	}
	/**
	 * Enable calling getFoo() and setFoo().
	 *
	 * @param string $name
	 * @param array $args
	 * @return mixed
	 */
	public function __call($name, $args) {
		if (preg_match('/^get([A-Z].*)/', $name, $matches)) {
			$setting = lcfirst($matches[1]);

			// See if the property exists and let __get() handle it.
			if (isset($this->constructorSettings[$setting])) {
				return $this->__get($setting);
			}

			// See if there is a getter.
			$getterName = "{$setting}Getter";
			if (isset($this->constructorSettings[$getterName])) {
				// First make sure there is a valid callback/getter if the setting was required.
				if (!isset($this->settings[$getterName]) && !$this->constructorSettings[$getterName]['isRequired']) {
					return null;
				}
				$getter = $this->settings[$getterName]['value'];
				if (!is_callable($getter)) {
					throw new BadMethodCallException(get_class($this) . ": $name(): There is no valid callback for \"$getterName\".");
				}

				// Execute the getter so we can check the result type.
				$result = call_user_func_array($getter, $args);
				$getterTypeParts = explode(' ', $this->settings[$getterName]['type']);

				// If there was a required return type specified after 'callback ', check the result.
				if (count($getterTypeParts) > 1) {
					$getterType = $getterTypeParts[1];
					$resultType = is_object($result)
						? get_class($result)
						: gettype($result);
					$isResultTypeOk = is_object($result)
						? is_a($result, $getterType)
						: $resultType === $getterType;
					if (!$this->constructorSettings[$getterName]['isRequired']) {
						$isResultTypeOk |= is_null($result);
					}
					if (!$isResultTypeOk) {
						throw new BadMethodCallException(get_class($this) . ": $name(): The \"$getterName\" callback did not return \"$getterType\", but \"$resultType\".");
					}
				}
				return $result;
			}
		} elseif (preg_match('/^set([A-Z].*)/', $name, $matches)) {
			$setting = lcfirst($matches[1]);

			// See if the property exists and let __set() handle it.
			if (isset($this->constructorSettings[$setting])) {
				if (count($args) !== 1) {
					throw new BadMethodCallException(get_class($this) . ": $name(): When using the setter function, specify exactly one argument.");
				}
				$this->__set($setting, $args[0]);
				return $this;
			}
		}
		throw new BadMethodCallException(get_class($this) . ": $name(): There is no method called \"$name\".");
	}

	/**
	 * Return all gettable properties as an associative array.
	 */
	public function toArray() {
		$array = array();
		foreach ($this->settings as $settingName => $settingData) {
			if ($settingData['isExportable']) {
				$array[$settingName] = $settingData['value'];
				// Handle getters.
				if (preg_match('/(.*)Getter$/', $settingName, $matches) !== false && $array[$settingName] instanceof Closure) {
					$reflector = new ReflectionFunction($array[$settingName]);
					// For properties with getters, only call them if they do not expect any arguments.
					if ($reflector->getNumberOfParameters() === 0) {
						$array[$matches[1]] = $array[$settingName]();
						unset($array[$settingName]);
						$settingName = $matches[1];
					} else {
						unset($array[$settingName]);
						continue;
					}
				}
				$thisClassName = get_class($this);
				$toArrayConverter = function ($var) use (&$toArrayConverter, $thisClassName, $settingName) {
					if (is_scalar($var) || is_null($var)) {
						// For scalars and NULL values, do nothing.
					} elseif (is_array($var)) {
						// For arrays, retain the keys and convert each value.
						foreach ($var as $key => $value) {
							$var[$key] = $toArrayConverter($value);
						}
					} elseif (is_a($var, 'IToArray')) {
						// For objects that implement toArra(), call that.
						$className = get_class($var);
						$var = $var->toArray();
						$var['_className'] = $className;
					} elseif (is_object($var)) {
						// For other objects, use the default array casting.
						$className = get_class($var);
						$var = (array)$var;
						$originalSettingName = $settingName;
						foreach ($var as $property => $value) {
							// Do not export protected and private properties.
							// See http://php.net/types.array#language.types.array.casting
							if (strpos($property, "\0") === 0) {
								unset($var[$property]);
								continue;
							}
							$settingName = "$originalSettingName->$property";
							$var[$property] = $toArrayConverter($value);
						}
						$var['_className'] = $className;
						$settingName = $originalSettingName;
					} else {
						$varType = gettype($var);
						throw new RuntimeException("$thisClassName: The \"$settingName\" property is of an unsupported type (\"$varType\") for array serialisation.");
					}
					return $var;
				};
				$array[$settingName] = $toArrayConverter($array[$settingName]);
				if (isset($settingData['type']) && isset($array[$settingName]['_className']) && $settingData['type'] === $array[$settingName]['_className']) {
					unset($array[$settingName]['_className']);
				}
			}
		}
		return $array;
	}
}
