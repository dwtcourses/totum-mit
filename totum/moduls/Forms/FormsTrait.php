<?php


namespace totum\config\totum\moduls\Forms;

use Psr\Http\Message\ServerRequestInterface;
use totum\common\calculates\Calculate;
use totum\common\calculates\CalculcateFormat;
use totum\common\Field;
use totum\common\Totum;
use totum\moduls\Table\WriteTableActions;
use totum\tableTypes\aTable;

trait FormsTrait
{

    private $path;
    private $sections;
    /**
     * @var CalculcateFormat
     */
    private $CalcTableFormat;
    /**
     * @var CalculcateFormat
     */
    private $CalcRowFormat;
    private $CalcFieldFormat;
    /**
     * @var Calculate
     */
    private $CalcSectionStatuses;

    public function __construct(ServerRequestInterface $Request, string $modulePath, aTable $Table = null, Totum $Totum = null)
    {
        parent::__construct($Request, $modulePath, $Table, $Totum);
        $this->post = json_decode((string)$Request->getBody(), true);

        $visibleFields = $this->Table->getVisibleFields('web', false);
        $clientFields = $this->fieldsForClient($visibleFields);

        foreach ($clientFields as &$field) {
            $field = array_replace($field, $this->FormsTableData['fields_else_params'][$field['name']] ?? []);

            if ($field['showInWebOtherPlacement'] ?? null) {
                $field['category'] = $field['showInWebOtherPlacement'];
                unset($field['showInWebOtherPlacement']);
            }
            if ($field['showInWebOtherOrd'] ?? null) {
                $field['ord'] = $field['showInWebOtherOrd'];
                unset($field['showInWebOtherOrd']);
            }
        }
        unset($field);
        $fields = array_column($clientFields, 'ord');
        array_multisort($fields, SORT_ASC, SORT_NUMERIC, $clientFields);
        $this->clientFields = $clientFields;


        $sections = [];
        foreach ($this->clientFields as $field) {
            switch ($field["category"]) {
                case "footer":
                    /*if ($field['column']) {
                        $field["category"] = "rows_footer";
                    }*/
                case 'param':

                    if ($field["tableBreakBefore"] && $field["sectionTitle"] ?? false) {
                        $name = null;
                        if (preg_match('/\*\*.*?name\s*:\s*([a-z_\-0-9]+)/i', $field['sectionTitle'], $matches)) {
                            $name = $matches[1];
                        }
                        $sections[$field["category"]][] = ['name' => $name, 'title' => $field['sectionTitle'], 'fields' => []];
                    } elseif (empty($sections[$field["category"]])) {
                        $sections[$field["category"]] [] = ['name' => null, 'title' => "", 'fields' => []];
                    }

                    $sections[$field["category"]][count($sections[$field["category"]]) - 1]['fields'][] = $field['name'];
                    break;

                default:

                    /*if (empty($sections['rows'])) {
                        $sections['rows'] = ['name' => "", 'title' => "", 'fields' => []];
                    }
                    $sections['rows']['fields'][] = $field['name'];*/
            }
        }
        $this->sections = $sections;


        $this->CalcTableFormat = new CalculcateFormat($this->Table->getTableRow()['table_format']);
        $this->CalcRowFormat = new CalculcateFormat($this->Table->getTableRow()['row_format']);


    }

    public function addFormsTableData($FormsTableData)
    {
        $this->FormsTableData = $FormsTableData;
        if ($this->FormsTableData['section_statuses_code'] && !preg_match(
                '/^\s*=\s*:\s*$/',
                $this->FormsTableData['section_statuses_code']
            )) {
            $this->CalcSectionStatuses = new Calculate($this->FormsTableData['section_statuses_code']);
        }
    }

    protected function getTableClientChangedData($data, $force = false)
    {
        $return['chdata']['rows'] = [];

        if ($this->Table->getChangeIds()['added']) {
            $return['chdata']['rows'] = array_intersect_key($this->Table->getTbl()['rows'],
                $this->Table->getChangeIds()['added']);
        }

        if ($this->Table->getChangeIds()['deleted']) {
            $return['chdata']['deleted'] = array_keys($this->Table->getChangeIds()['deleted']);
        }
        $modify = $data['modify'];
        unset($modify['params']);


        $fieldFormatS = $this->getTableFormats(false, $this->Table->getTbl()['rows']);

        if (($modify += $this->Table->getChangeIds()['changed']) && $fieldFormatS['r']) {
            foreach ($modify as $id => $changes) {
                if (empty($this->Table->getTbl()['rows'][$id]) || !empty($fieldFormatS['r'][$id]['hidden'])) continue;
                $return['chdata']['rows'][$id] = $this->Table->getTbl()['rows'][$id];
                $return['chdata']['rows'][$id]['id'] = $id;
            }
        }

        $return['chdata']['params'] = array_intersect_key($this->Table->getTbl()['params'], $fieldFormatS['p']);
        $return['chdata'] = $this->getValuesForClient($return['chdata'], $fieldFormatS);
        $return['chdata']['f'] = $fieldFormatS;

        $return['updated'] = $this->Table->getSavedUpdated();

        return $return;
    }
    public function getTableData($withRecalculate = true)
    {
        $result = parent::getFullTableData($withRecalculate);

        $formats = $this->getTableFormats(false, $this->Table->getTbl()['rows']);
        $data['params'] = array_intersect_key($this->Table->getTbl()['params'], $formats['p']);
        $data['rows'] = [];
        foreach ($this->Table->getTbl()['rows'] as $row) {
            if (!empty($row['is_del'])) continue;
            if (key_exists($row['id'], $formats['r'])) {
                $newRow = ['id' => $row['id']];
                foreach ($row as $k => $v) {
                    if (key_exists($k, $formats['r'][$row['id']])) {
                        $newRow[$k] = $v;
                    }
                }
                $data['rows'][] = $newRow;
            }
        }
        $data = $this->getValuesForClient($data, $formats, true);

        $result = [
            'tableRow' => $this->tableRowForClient($this->Table->getTableRow())
            , 'f' => $formats
            , 'c' => $this->getTableControls()
            , 'fields' => $this->clientFields
            , 'sections' => $this->sections
            , 'error' => $error ?? null
            /*, 'data' => $data['rows']*/
            , 'data_params' => $data['params']
            , 'updated' => $this->Table->getSavedUpdated()

        ];
        return $result;
    }

    protected
    function getValuesForClient($data, &$formats, $isFirstLoad = false)
    {
        /*foreach (($data['rows'] ?? []) as $i => $row) {

            $newRow = ['id' => ($row['id'] ?? null)];
            if (!empty($row['InsDel'])) {
                $newRow['InsDel'] = true;
            }
            foreach ($row as $fName => $value) {
                if (key_exists($fName, $this->Table->getFields())) {
                    Field::init($this->Table->getFields()[$fName], $this->Table)->addViewValues('edit',
                        $value,
                        $row,
                        $this->Table->getTbl());
                    $newRow[$fName] = $value;
                }
            }
            $data['rows'][$i] = $newRow;
        }*/
        if (!empty($data['params'])) {
            foreach ($data['params'] as $fName => &$value) {
                $field = $this->Table->getFields()[$fName];

                switch ($field['type']) {
                    case 'file':
                        Field::init($field, $this->Table)->addViewValues('edit',
                            $value,
                            $this->Table->getTbl()['params'],
                            $this->Table->getTbl());

                        $value['v_'] = [];
                        foreach ($value['v'] as $val) {
                            $value['v_'][] = $this->getHttpFilePath() . $val['file'];
                        }
                        unset($val);
                        break;
                    case 'select':
                        switch (strval($formats['p'][$fName]['viewtype'])) {
                            case 'viewimage':
                                $Field = Field::init($this->Table->getFields()[$fName], $this->Table);
                                $fileData = $Field->getPreviewHtml($data['params'][$fName]['v'],
                                    $this->Table->getTbl()['params'],
                                    $this->Table->getTbl(),
                                    true);
                                $data['params'][$fName]['v_'] = $this->getHttpFilePath() . ($fileData[$formats['p'][$fName]['viewdata']['picture_name'] ?? ''][1][0]['file'] ?? '');
                                break 2;
                            case "":
                                Field::init($field, $this->Table)->addViewValues('edit',
                                    $value,
                                    $this->Table->getTbl()['params'],
                                    $this->Table->getTbl());
                                break;
                            default:
                                if ($isFirstLoad || $field['codeSelectIndividual']) {
                                    $formats['p'][$fName]['selects'] = $this->getEditSelect(['field' => $fName, 'item' => array_map(function ($val) {
                                        return $val['v'];
                                    },
                                        $this->Table->getTbl()['params'])],
                                        '',
                                        null,
                                        $formats['p'][$fName]['viewtype']);

                                }
                        }

                    default:
                        Field::init($field, $this->Table)->addViewValues('edit',
                            $value,
                            $this->Table->getTbl()['params'],
                            $this->Table->getTbl());
                }
            }
            unset($value);
        }


        return $data;
    }

    function getEditSelect($data = null, $q = null, $parentId = null, $type = null)
    {
        $fields = $this->Table->getFields();

        if (!($field = $fields[$data['field']] ?? null))
            throw new errorException('Не найдено поле [[' . $data['field'] . ']]. Возможно изменилась структура таблицы. Перегрузите страницу');
        if (!in_array($field['type'],
            ['select', 'tree'])) throw new errorException('Ошибка - поле не типа select/tree');

        $this->Table->loadDataRow();


        $row = $data['item'];
        foreach ($row as $k => &$v) {
            if ($k !== 'id') {
                $v = ['v' => $v];
            }
        }

        $row = $row + $this->Table->getTbl()['params'];


        /** @var Select $Field */
        $Field = Field::init($field, $this->Table);

        if ($type) {
            $list = [];
            $indexed = [];
            foreach ($Field->calculateSelectListWithPreviews($row[$field['name']],
                $row,
                $this->Table->getTbl()) as $val => $data) {
                if (!empty($data['2'])) {
                    $data['2'] = $data['2']();
                }

                $val = strval($val);

                $list[] = $val;
                $indexed[$val] = $data;
                switch ($type) {
                    case 'checkboxpicture':
                        foreach ($indexed[$val][array_key_last($indexed[$val])] as $kPreview => $vls) {
                            switch ($vls[2]) {
                                case 'file':
                                    $pVal = $this->getHttpFilePath() . $vls[1][0]['file'];
                                    break;
                                default:
                                    $pVal = $vls[1];
                            }
                            $indexed[$val][array_key_last($indexed[$val])][$kPreview] = $pVal;
                        }

                        break;
                    default:
                        foreach ($indexed[$val][array_key_last($indexed[$val])] as $name => &$vls) {
                            switch ($vls[2]) {
                                case 'file':
                                    foreach ($vls[1] as &$f) {
                                        $f = $this->getHttpFilePath() . $f['file'];
                                    }
                                    unset($f);
                                    break;
                            }
                            array_unshift($vls, $name);
                        }
                        $indexed[$val][array_key_last($indexed[$val])] = array_values($indexed[$val][array_key_last($indexed[$val])]);
                        unset($vls);
                }
            }


            return ['indexed' => $indexed, 'list' => $list, 'sliced' => false];
        }
        $list = $Field->calculateSelectList($row[$field['name']], $row, $this->Table->getTbl());

        return $Field->cropSelectListForWeb($list, $row[$field['name']]['v'], $q, $parentId);
    }

    protected function getTableFormats(bool $onlyEditable, $rows)
    {
        $tableFormats = $this->CalcTableFormat->getFormat('TABLE', [], $this->Table->getTbl(), $this->Table);
        $tableJsonFromRow = $this->FormsTableData['format_static'];

        $tableFormats['sections'] = $tableFormats['sections'] ?? [];

        if ($this->CalcSectionStatuses) {
            $sectionFormats = $this->CalcSectionStatuses->exec(
                ['name' => 'SECTION FORMATS'],
                null,
                [],
                $this->Table->getTbl()['params'],
                [],
                $this->Table->getTbl(),
                $this->Table
            );
            if ($sectionFormats && is_array($sectionFormats)) {
                foreach ($sectionFormats as $k => $status) {
                    $tableFormats['sections'][$k]['status'] = $status;
                }
            }
        }


        if (!empty($tableFormats['rowstitle'])) {
            if (preg_match('/^([a-z0-9_]{1,})\s*\:\s*(.*)/', $tableFormats['rowstitle'], $matches)) {
                $tableFormats['rowsTitle'] = $matches[2];
                $tableFormats['rowsName'] = $matches[1];
            } else {
                $tableFormats['rowsName'] = "";
                $tableFormats['rowsTitle'] = $tableFormats['rowstitle'];
            }
        }
        unset($tableFormats['rowstitle']);

        /*2-edit; 1- view; 0 - hidden*/
        $getSectionEditType = function ($sectionName) use ($tableFormats) {
            if (!$sectionName || !key_exists(
                    $sectionName,
                    $tableFormats['sections']
                )) {
                return 2;
            }
            switch ($tableFormats['sections'][$sectionName]['status'] ?? null) {
                case 'edit':
                    return 2;
                case 'view':
                    return 1;
                default:
                    return 0;
            }
        };


        if ($onlyEditable) {
            $result = [];
            if (!$this->onlyRead) {
                foreach ($this->sections as $category => $sec) {
                    switch ($category) {
                        case 'rows':
                            $section = $sec;
                            if ($st = $getSectionEditType($section['name'])) {

                                /*Если секция редактируемая*/
                                if ($st == 2) {
                                    foreach ($rows as $row) {
                                        $rowFormat = $this->CalcRowFormat->getFormat(
                                            'ROW',
                                            $row,
                                            $this->Table->getTbl(),
                                            $this->Table
                                        );
                                        if (empty($rowFormat['block'])) {
                                            foreach ($section['fields'] as $fieldName) {
                                                if (!key_exists($fieldName, $this->clientFields)) {
                                                    continue;
                                                }

                                                $FieldFormat = $this->CalcFieldFormat[$fieldName]
                                                    ?? ($this->CalcFieldFormat[$fieldName]
                                                        = new CalculcateFormat($this->Table->getFields()[$fieldName]['format']));
                                                $format = $FieldFormat->getFormat(
                                                    $fieldName,
                                                    $row,
                                                    $this->Table->getTbl(),
                                                    $this->Table
                                                );

                                                if (empty($format['block']) && empty($format['hidden'])) {
                                                    $result[$row['id']][$fieldName] = true;
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                        default:
                            foreach ($sec as $section) {
                                if ($st = $getSectionEditType($section['name'])) {
                                    /*Если секция редактируемая*/
                                    if ($st == 2) {
                                        foreach ($section['fields'] as $fieldName) {
                                            if (!key_exists($fieldName, $this->clientFields)) {
                                                continue;
                                            }

                                            $FieldFormat = $this->CalcFieldFormat[$fieldName]
                                                ?? ($this->CalcFieldFormat[$fieldName]
                                                    = new CalculcateFormat($this->Table->getFields()[$fieldName]['format']));
                                            $format = $FieldFormat->getFormat(
                                                $fieldName,
                                                $this->Table->getTbl()['params'],
                                                $this->Table->getTbl(),
                                                $this->Table
                                            );

                                            if (empty($format['block']) && empty($format['hidden'])) {
                                                $result[$fieldName] = true;
                                            }
                                        }
                                    }
                                }
                            }
                            break;
                    }
                }
            }


            $result = ['t' => $tableFormats] + $result;
        } else {
            $result = ['t' => $tableFormats, 'r' => [], 'p' => []];

            foreach ($this->sections as $category => $sec) {
                switch ($category) {
                    /*case 'rows':
                        $section = $sec;
                        $sectionStatus = $getSectionEditType($section['name']);
                        foreach ($rows as $row) {
                            $rowFormat = $this->CalcRowFormat->getFormat('ROW',
                                $row,
                                $this->Table->getTbl(),
                                $this->Table);

                            $result['r'][$row['id']]['f'] = $rowFormat;

                            foreach ($section['fields'] as $fieldName) {

                                if (!key_exists($fieldName, $this->clientFields)) continue;
                                if (!$sectionStatus) {
                                    $result['r'][$row['id']][$fieldName] = [];
                                } else {

                                    $FieldFormat = $this->CalcFieldFormat[$fieldName]
                                        ?? ($this->CalcFieldFormat[$fieldName]
                                            = new CalculcateFormat($this->Table->getFields()[$fieldName]['format']));
                                    $format = $FieldFormat->getFormat($fieldName,
                                        $row,
                                        $this->Table->getTbl(),
                                        $this->Table);

                                    if (empty($format['hidden'])) {
                                        $result['r'][$row['id']][$fieldName] = $format;
                                    } else {
                                        $result['r'][$row['id']][$fieldName] = ['hidden' => true];
                                    }
                                }
                            }
                        }
                        break;*/
                    default:
                        foreach ($sec as $section) {
                            $sectionStatus = $getSectionEditType($section['name']);
                            foreach ($section['fields'] as $fieldName) {
                                if (!key_exists($fieldName, $this->clientFields)) {
                                    continue;
                                }

                                if ($sectionStatus) {
                                    $FieldFormat = $this->CalcFieldFormat[$fieldName]
                                        ?? ($this->CalcFieldFormat[$fieldName]
                                            = new CalculcateFormat($this->Table->getFields()[$fieldName]['format']));
                                    $format = $FieldFormat->getFormat(
                                        $fieldName,
                                        $this->Table->getTbl()['params'],
                                        $this->Table->getTbl(),
                                        $this->Table
                                    );
                                    if (empty($format['hidden'])) {
                                        $result['p'][$fieldName] = $format;
                                    } else {
                                        $result['p'][$fieldName] = ['hidden' => true];
                                    }
                                } else {
                                    $result['p'][$fieldName] = [];
                                }
                            }
                        }
                        break;
                }
            }
            $result['t']['s'] = $result['t']['sections'] ?? [];
            unset($result['t']['sections']);
        }
        return array_replace_recursive($tableJsonFromRow, $result);
    }

    protected function getHttpFilePath()
    {
        $host = $this->Totum->getConfig()->getFullHostName();
        $protocol = (!empty($_SERVER['HTTPS']) && 'off' !== strtolower($_SERVER['HTTPS']) ? 'https://' : 'http://');
        $host = 'calc-totum.totum.online';
        $protocol = 'https://';
        return $this->path ?? ($this->path = ($protocol . $host . '/fls/'));

    }

    protected function getTableControls()
    {
        $result = [];
        $result['deleting'] = is_a($this, WriteTableActions::class) && $this->Table->isUserCanAction('delete');
        $result['adding'] = is_a($this, WriteTableActions::class) && $this->Table->isUserCanAction('insert');
        $result['duplicating'] = is_a($this, WriteTableActions::class) && $this->Table->isUserCanAction('duplicate');
        $result['sorting'] = is_a($this, WriteTableActions::class) && $this->Table->isUserCanAction('reorder');
        $result['editing'] = is_a($this, WriteTableActions::class);
        return $result;
    }
}