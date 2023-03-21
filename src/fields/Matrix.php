<?php

namespace craft\feedme\fields;

use Cake\Utility\Hash;
use craft\feedme\base\Field;
use craft\feedme\base\FieldInterface;
use craft\feedme\Plugin;
use craft\fields\Matrix as MatrixField;

/**
 *
 * @property-read string $mappingTemplate
 */
class Matrix extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    /**
     * @var string
     */
    public static string $name = 'Matrix';

    /**
     * @var string
     */
    public static string $class = MatrixField::class;

    // Templates
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getMappingTemplate(): string
    {
        return 'feed-me/_includes/fields/matrix';
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function parseField(): mixed
    {
        $preppedData = [];
        $fieldData = [];
        $complexFields = [];

        $blocks = Hash::get($this->fieldInfo, 'blocks');

        // Before we do anything, we need to extract the data from our feed and normalise it. This is especially
        // complex due to sub-fields, which each can be a variety of fields and formats, compounded by multiple or
        // Matrix blocks - we don't know! We also need to be careful of the order data is in the feed to be
        // reflected in the field - phew!
        //
        // So, in order to keep data in the order provided in our feed, we start there (as opposed to looping through blocks)

        foreach ($this->feedData as $nodePath => $value) {
            // Get the field mapping info for this node in the feed
            $fieldInfo = $this->_getFieldMappingInfoForNodePath($nodePath, $blocks);
            // error_log(print_r($fieldInfo, 1) . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

            // If this is data concerning our Matrix field and blocks
            if ($fieldInfo) {
                $blockHandle = $fieldInfo['blockHandle'];
                $subFieldHandle = $fieldInfo['subFieldHandle'];
                $subFieldInfo = $fieldInfo['subFieldInfo'];
                $isComplexField = $fieldInfo['isComplexField'];

                $nodePathSegments = explode('/', $nodePath);
                $blockIndex = Hash::get($nodePathSegments, 1);

                if (!is_numeric($blockIndex)) {
                    // Try to check if its only one-level deep (only importing one block type)
                    // which is particularly common for JSON.
                    $blockIndex = Hash::get($nodePathSegments, 2);

                    if (!is_numeric($blockIndex)) {
                        $blockIndex = Hash::get($nodePathSegments, 3);

                        if (!is_numeric($blockIndex)) {
                            $blockIndex = Hash::get($nodePathSegments, 4);

                            if (!is_numeric($blockIndex)) {
                                $blockIndex = 0;
                            }
                        }
                    }
                }
                if ($blockHandle == 'part') {
                    for ($i = count($nodePathSegments) - 1; $i >= 0; $i--) {
                        if (is_numeric($nodePathSegments[$i])) {
                            $blockIndex = $nodePathSegments[$i];
                            // error_log("Overridden $blockIndex = " . $blockIndex, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                            break;
                        }
                    }
                }

                $key = $blockIndex . '.' . $blockHandle . '.' . $subFieldHandle;

                // Check for complex fields (think Table, Super Table, etc), essentially anything that has
                // sub-fields, and doesn't have data directly mapped to the field itself. It needs to be
                // accumulated here (so its in the right order), but grouped based on the field and block
                // its in. A bit annoying, but no better ideas...
                // if ($isComplexField) {
                if ($isComplexField || $blockHandle == 'part') {
                    $complexFields[$key]['handle'] = $blockHandle;
                    $complexFields[$key]['info'] = $subFieldInfo;
                    $complexFields[$key]['data'][$nodePath] = $value;
                    // error_log("complexField key = " . print_r($key, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                    // error_log("    => " . "nodePath key = " . print_r($nodePath, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                    // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                    continue;
                }

                // Swap out the node-path stored in the field-mapping info, because
                // it'll be generic MatrixBlock/Images not MatrixBlock/0/Images/0 like we need
                $subFieldInfo['node'] = $nodePath;

                // Parse each field via their own fieldtype service
                $parsedValue = $this->_parseSubField($this->feedData, $subFieldHandle, $subFieldInfo);

                // Finish up with the content, also sort out cases where there's array content
                if (isset($fieldData[$key]) && is_array($fieldData[$key])) {
                    $fieldData[$key] = array_merge_recursive($fieldData[$key], $parsedValue);
                } else {
                    $fieldData[$key] = $parsedValue;
                }
            }
        }
        // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

        // Handle some complex fields that don't directly have nodes, but instead have nested properties mapped.
        // They have their mapping setup on sub-fields, and need to be processed all together, which we've already prepared.
        // Additionally, we only want to supply each field with a sub-set of data related to that specific block and field
        // otherwise, we get the field class processing all blocks in one go - not what we want.
        foreach ($complexFields as $key => $complexInfo) {
            $parts = explode('.', $key);
            $subFieldHandle = $parts[2];

            $subFieldInfo = Hash::get($complexInfo, 'info');
            $nodePaths = Hash::get($complexInfo, 'data');
            $handle = Hash::get($complexInfo, 'handle');




            // $parsedValue = $this->_parseSubField($nodePaths, $subFieldHandle, $subFieldInfo);
            // https://github.com/craftcms/feed-me/discussions/889#discussioncomment-1169950
            // La riga da sostituire sembrerebbe essere diventata la 126, non la 113 come indicato da lui nel commento
            // HACK START
            // error_log(print_r($complexInfo, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
            // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

            // error_log("HACK START" . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
            $nodePathsArray = [];
            foreach ($nodePaths as $nodePathKey => $nodePathVal) {
                // Get the node number
                $pathArray = explode("/", $nodePathKey);
                $nodeNumber = $pathArray[count($pathArray) - 2];
                $nodePathsArray[$nodeNumber][$nodePathKey] = $nodePathVal;
            }
            // error_log(print_r($nodePathsArray, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

            $i = 1;
            $parsedValue = array();
            foreach ($nodePathsArray as $nodePathsArrayKey => $nodePathsArrayValue) {
                $parsedValueTemp = $this->_parseSubField($nodePathsArrayValue, $subFieldHandle, $subFieldInfo);
                // error_log("nodePathsArrayValue = " . print_r($nodePathsArrayValue, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log("subFieldHandle = " . print_r($subFieldHandle, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log("subFieldInfo = " . print_r($subFieldInfo, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log("parsedValueTemp = " . print_r($parsedValueTemp, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

                // $parsedValue["new".$i] = reset($parsedValueTemp);
                $parsedValue["new" . $i] = is_array($parsedValueTemp) ? reset($parsedValueTemp) : $parsedValueTemp;
                // error_log("parsedValue[new" . $i . "] = " . print_r($parsedValue["new".$i], 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                $i++;
            }
            // error_log("HACK END" . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
            // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
            // HACK END



            // il problema è qua. lui genera un unico merged array con dentro 3 valori array,
            // anziché creare 3 sottonodi con un valore ciascuno
            if ($handle == 'part') {
                // error_log($subFieldInfo . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
                if (isset($fieldData[$key])) {
                    $fieldData[$key] = array_merge_recursive($fieldData[$key], reset($parsedValue));
                } else {
                    if ($subFieldHandle === 'finishing') {
                        $fieldData[$key] = $parsedValue;
                    } else {
                        $fieldData[$key] = reset($parsedValue);
                    }
                }
            } else {
                if (isset($fieldData[$key])) {
                    $fieldData[$key] = array_merge_recursive($fieldData[$key], $parsedValue);
                } else {
                    $fieldData[$key] = $parsedValue;
                }
            }
        }

        ksort($fieldData, SORT_NUMERIC);
        // error_log("fieldData" . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
        // error_log(print_r($fieldData, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
        // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

        // $order = 0;

        // New, we've got a collection of prepared data, but its formatted a little rough, due to catering for
        // sub-field data that could be arrays or single values. Let's build our Matrix-ready data
        foreach ($fieldData as $blockSubFieldHandle => $value) {
            $handles = explode('.', $blockSubFieldHandle);
            $blockIndex = 'new' . ($handles[0] + 1);
            $blockHandle = $handles[1];
            $subFieldHandle = $handles[2];

            $disabled = Hash::get($this->fieldInfo, 'blocks.' . $blockHandle . '.disabled', false);
            $collapsed = Hash::get($this->fieldInfo, 'blocks.' . $blockHandle . '.collapsed', false);

            // Prepare an array that's ready for Matrix to import it
            $preppedData[$blockIndex . '.type'] = $blockHandle;
            // $preppedData[$blockIndex . '.order'] = $order;
            $preppedData[$blockIndex . '.enabled'] = !$disabled;
            $preppedData[$blockIndex . '.collapsed'] = $collapsed;
            $preppedData[$blockIndex . '.fields.' . $subFieldHandle] = $value;
            // $order++;
        }
        // error_log("preppedData" . PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
        // error_log(print_r($preppedData, 1), 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');
        // error_log(PHP_EOL, 3, '/var/www/zucchettikos.gigadesignstudio.com/admin/storage/logs/feed-me-debug.log');

        return Hash::expand($preppedData);
    }


    // Private Methods
    // =========================================================================

    /**
     * @param $nodePath
     * @param $blocks
     * @return array|null
     */
    private function _getFieldMappingInfoForNodePath($nodePath, $blocks): ?array
    {
        foreach ($blocks as $blockHandle => $blockInfo) {
            $fields = Hash::get($blockInfo, 'fields');

            $feedPath = preg_replace('/(\/\d+\/)/', '/', $nodePath);
            $feedPath = preg_replace('/^(\d+\/)|(\/\d+)/', '', $feedPath);

            foreach ($fields as $subFieldHandle => $subFieldInfo) {
                $node = Hash::get($subFieldInfo, 'node');

                $nestedFieldNodes = Hash::extract($subFieldInfo, 'fields.{*}.node');

                if ($nestedFieldNodes) {
                    foreach ($nestedFieldNodes as $nestedFieldNode) {
                        if ($feedPath == $nestedFieldNode) {
                            return [
                                'blockHandle' => $blockHandle,
                                'subFieldHandle' => $subFieldHandle,
                                'subFieldInfo' => $subFieldInfo,
                                'nodePath' => $nodePath,
                                'isComplexField' => true,
                            ];
                        }
                    }
                }

                if ($feedPath == $node || $node === 'usedefault') {
                    return [
                        'blockHandle' => $blockHandle,
                        'subFieldHandle' => $subFieldHandle,
                        'subFieldInfo' => $subFieldInfo,
                        'nodePath' => $nodePath,
                        'isComplexField' => false,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param $feedData
     * @param $subFieldHandle
     * @param $subFieldInfo
     * @return mixed
     */
    private function _parseSubField($feedData, $subFieldHandle, $subFieldInfo): mixed
    {
        $subFieldClassHandle = Hash::get($subFieldInfo, 'field');

        $subField = Hash::extract($this->field->getBlockTypeFields(), '{n}[handle=' . $subFieldHandle . ']')[0];

        $class = Plugin::$plugin->fields->getRegisteredField($subFieldClassHandle);
        $class->feedData = $feedData;
        $class->fieldHandle = $subFieldHandle;
        $class->fieldInfo = $subFieldInfo;
        $class->field = $subField;
        $class->element = $this->element;
        $class->feed = $this->feed;

        // Get our content, parsed by this fields service function
        return $class->parseField();
    }
}
