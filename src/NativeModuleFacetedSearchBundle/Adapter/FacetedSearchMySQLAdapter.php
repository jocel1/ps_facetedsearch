<?php
namespace NativeModuleFacetedSearchBundle\Adapter;

use Doctrine\Common\Collections\ArrayCollection;

class FacetedSearchMySQLAdapter extends FacetedSearchAbstract {
    const LEFT_JOIN = 'LEFT JOIN';
    const INNER_JOIN = 'INNER JOIN';

    private $referenceTable = _DB_PREFIX_.'product';
    private $referenceAlias = 'p';

    private function setReferenceTable($query, $alias) {
        $this->referenceTable = $query;
        $this->referenceAlias = $alias;
    }

    /**
     * @return array
     */
    protected function getFieldMapping() {
        $filterToTableMapping = [
            'id_product_attribute' => ['tableName' => 'product_attribute', 'tableAlias' => 'pa', 'joinCondition' => '(p.id_product = pa.id_product)', 'joinType' => self::LEFT_JOIN],
            'id_attribute' => ['tableName' => 'product_attribute_combination', 'tableAlias' => 'pac', 'joinCondition' => '(pa.id_product_attribute = pac.id_product_attribute)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_product_attribute'],
            'id_attribute_group' => ['tableName' => 'attribute', 'tableAlias' => 'a', 'joinCondition' => '(a.id_attribute = pac.id_attribute)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_attribute'],
            'id_feature' => ['tableName' => 'feature_product', 'tableAlias' => 'fp', 'joinCondition' => '(p.id_product = fp.id_product)', 'joinType' => self::LEFT_JOIN],
            'id_shop' => ['tableName' => 'product_shop', 'tableAlias' => 'ps', 'joinCondition' => '(p.id_product = ps.id_product)', 'joinType' => self::INNER_JOIN],
            'id_feature_value' => ['tableName' => 'feature_product', 'tableAlias' => 'fp', 'joinCondition' => '(p.id_product = fp.id_product)', 'joinType' => self::LEFT_JOIN],
            'id_category' => ['tableName' => 'category_product', 'tableAlias' => 'cp', 'joinCondition' => '(p.id_product = cp.id_product)', 'joinType' => self::INNER_JOIN],
            'position' => ['tableName' => 'category_product', 'tableAlias' => 'cp', 'joinCondition' => '(p.id_product = cp.id_product)', 'joinType' => self::INNER_JOIN],
            'nleft' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_category'],
            'nright' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_category'],
            'level_depth' => ['tableName' => 'category', 'tableAlias' => 'c', 'joinCondition' => '(cp.id_category = c.id_category AND c.active=1)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_category'],
            'out_of_stock' => ['tableName' => 'stock_available', 'tableAlias' => 'sa', 'joinCondition' => '(p.id_product=sa.id_product AND pa.id_product_attribute = sa.id_product_attribute)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'id_product_attribute'],
            'id_group' => ['tableName' => 'category_group', 'tableAlias' => 'cg', 'joinCondition' => '(cg.id_category = c.id_category)', 'joinType' => self::LEFT_JOIN, 'dependencyField' => 'nleft'],
        ];

        return $filterToTableMapping;
    }

    /**
     * @return string
     */
    public function getQuery() {
        $filterToTableMapping = $this->getFieldMapping();
        $this->computeOrderByField($filterToTableMapping);
        if (empty($this->selectFields) && empty($this->filters) && empty($this->groupFields)) {
            $query = $this->referenceTable;
            $this->orderField = '';
        } else {
            $query = 'SELECT ';

            $selectFields = $this->computeSelectFields($filterToTableMapping);
            $whereConditions = $this->computeWhereConditions($filterToTableMapping);
            $joinConditions = $this->computeJoinConditions($filterToTableMapping);
            $this->computeGroupByFields($filterToTableMapping);

            $query .= implode(', ', $selectFields) . ' FROM ' . $this->referenceTable . ' ' . $this->referenceAlias;

            foreach ($joinConditions as $tableName => $joinAliasConditionInfos) {
                foreach ($joinAliasConditionInfos as $tableAlias => $joinConditionInfos) {
                    $query .= ' ' . $joinConditionInfos['joinType'] . ' ' . _DB_PREFIX_ . $tableName . ' ' . $tableAlias . ' ON ' . $joinConditionInfos['joinCondition'];
                }
            }

            if (!empty($whereConditions)) {
                $query .= ' WHERE ' . implode(' AND ', $whereConditions);
            }

            if ($this->groupFields) {
                $query .= ' GROUP BY ' . implode(', ', $this->groupFields);
            }
        }

        if ($this->orderField) {
            $query .= ' ORDER BY ' . $this->orderField . ' ' . $this->orderDirection;
        }

        if ($this->limit !== null) {
            $query .= ' LIMIT ' . $this->offset . ', ' . $this->limit;
        }

        //echo $query."<br />";

        return $query;
    }

    private function computeOrderByField($filterToTableMapping) {
        if (empty($this->orderField) || strpos($this->orderField, '.') !== false) {
            return;
        }
        if (array_key_exists($this->orderField, $filterToTableMapping)) {
            $joinMapping = $filterToTableMapping[$this->orderField];
            $this->orderField = $joinMapping['tableAlias'].'.'.$this->orderField;
        } else {
            $this->orderField = 'p.'.$this->orderField;
        }
    }

    private function computeGroupByFields($filterToTableMapping) {
        if (empty($this->groupFields)) {
            return;
        }

        foreach($this->groupFields as $key => $values) {
            if (strpos($values, '.') !== false) {
                continue;
            }
            if (array_key_exists($values, $filterToTableMapping)) {
                $joinMapping = $filterToTableMapping[$values];
                $this->groupFields[$key] = $joinMapping['tableAlias'].'.'.$values;
            } else {
                $this->groupFields[$key] = 'p.'.$values;
            }
        }
    }

    /**
     * @param array $filterToTableMapping
     *
     * @return ArrayCollection
     */
    private function computeJoinConditions($filterToTableMapping) {
        $joinList = new ArrayCollection();

        foreach($this->selectFields as $key => $selectField) {
            if (array_key_exists($selectField, $filterToTableMapping)) {
                $joinMapping = $filterToTableMapping[$selectField];
                $this->addJoinConditions($joinList, $joinMapping, $filterToTableMapping);
            }
        }

        foreach($this->filters as $filterName => $filterContent) {
            if (array_key_exists($filterName, $filterToTableMapping)) {
                $joinMapping = $filterToTableMapping[$filterName];
                $this->addJoinConditions($joinList, $joinMapping, $filterToTableMapping);
            }
        }

        foreach($this->groupFields as $groupFields => $filterContent) {
            if (array_key_exists($groupFields, $filterToTableMapping)) {
                $joinMapping = $filterToTableMapping[$groupFields];
                $this->addJoinConditions($joinList, $joinMapping, $filterToTableMapping);
            }
        }

        if (array_key_exists($this->orderField, $filterToTableMapping)) {
            $joinMapping = $filterToTableMapping[$this->orderField];
            $this->addJoinConditions($joinList, $joinMapping, $filterToTableMapping);
        }

        return $joinList;
    }

    /**
     * @param array           $filterToTableMapping
     *
     * @return array
     */
    private function computeSelectFields($filterToTableMapping) {
        $selectFields = [];
        foreach($this->selectFields as $key => $selectField) {
            $selectAlias = 'p';
            if (array_key_exists($selectField, $filterToTableMapping)) {
                $joinMapping = $filterToTableMapping[$selectField];
                $selectAlias = $joinMapping['tableAlias'];
            }
            if (strpos($selectField, '(') !== false) {
                $selectFields[] = $selectField;
            } else {
                $selectFields[] = $selectAlias . '.' . $selectField;
            }
        }

        return $selectFields;
    }

    /**
     * @param array           $filterToTableMapping
     *
     * @return array
     */
    private function computeWhereConditions($filterToTableMapping) {
        $whereConditions = [];
        foreach($this->filters as $filterName => $filterContent) {
            foreach($filterContent as $operator => $values) {
                $selectAlias = 'p';
                if (array_key_exists($filterName, $filterToTableMapping)) {
                    $joinMapping = $filterToTableMapping[$filterName];
                    $selectAlias = $joinMapping['tableAlias'];
                }

                if ($operator === '=') {
                    if (count($values) == 1) {
                        $whereConditions[] = $selectAlias . '.' . $filterName . $operator . "'" . current($values) . "'";
                    } else {
                        $whereConditions[] = $selectAlias . '.' . $filterName . ' IN ('.implode(', ', array_map(function ($value) { return "'".$value."'"; }, $values)).')';
                    }
                } else {
                    $orConditions = [];
                    foreach($values as $value) {
                        $orConditions[] = $selectAlias . '.' . $filterName . $operator . $value;
                    }
                    $whereConditions[] = implode(' OR ', $orConditions);
                }
            }
        }

        return $whereConditions;
    }

    /**
     * @param ArrayCollection $joinList
     * @param array           $joinMapping
     * @param array           $filterToTableMapping
     *
     */
    private function addJoinConditions(ArrayCollection $joinList, $joinMapping, $filterToTableMapping) {
        if (array_key_exists('dependencyField', $joinMapping)) {
            $dependencyJoinMapping = $filterToTableMapping[$joinMapping['dependencyField']];
            $this->addJoinConditions($joinList, $dependencyJoinMapping, $filterToTableMapping);
        }
        $joinInfos[$joinMapping['tableAlias']] = [
            'joinCondition' => $joinMapping['joinCondition'],
            'joinType' => $joinMapping['joinType']
        ];

        $joinList->set($joinMapping['tableName'], $joinInfos);
    }

    public function execute() {
        return \Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS($this->getQuery());
    }

    public function getMinMaxValue($fieldName)
    {
        $mysqlAdapter = new self();
        $mysqlAdapter->setReferenceTable($this->referenceTable, $this->referenceAlias);
        $mysqlAdapter->setFilters($this->getFilters());
        $this->setSelectFields(['MIN('.$fieldName.') as min, MAX('.$fieldName.') as max']);

        $result = $this->execute();

        return [0 => $result[0]['min'], 1 => $result[0]['max']];
    }

    public function getFieldRanges($fieldName, $outputLength)
    {
        $diff = 'SELECT ROUND((-MIN(p.price) + MAX(p.price)) / '.$outputLength.') AS diff FROM ps_category c STRAIGHT_JOIN ps_category_product cp ON (c.id_category = cp.id_category AND c.nleft >= 17 AND c.nright <= 78 AND c.active = 1) STRAIGHT_JOIN ps_product_shop product_shop ON (product_shop.id_product = cp.id_product) STRAIGHT_JOIN ps_product p ON (p.id_product=cp.id_product)';
        $outputRange = 'SELECT FLOOR(p.price/'.$diff.')*'.$diff.' as range_start, (FLOOR(p.price/'.$diff.')+1)*'.$diff.'-1 as range_end, COUNT(DISTINCT(p.id_product)) c FROM ps_category c STRAIGHT_JOIN ps_category_product cp ON (c.id_category = cp.id_category AND c.nleft >= 17 AND c.nright <= 78 AND c.active = 1) STRAIGHT_JOIN ps_product_shop product_shop ON (product_shop.id_product = cp.id_product) STRAIGHT_JOIN ps_product p ON (p.id_product=cp.id_product) GROUP BY FLOOR(p.price / '.$diff.')';
        return [[0 /*'range_start'*/ => '0', 1 /*'range_end'*/ => '100', 'nbr' => '0']];
    }

    public function count()
    {
        $mysqlAdapter = $this->getFilteredSearchAdapter();
        $mysqlAdapter->setFilters($this->getFilters());
        $this->setSelectFields(['COUNT(*) c']);
        $this->setLimit(null);
        $this->setOrderField('');

        $result = $this->execute();

        return $result[0]['c'];
    }

    public function getFilteredSearchAdapter() {
        $mysqlAdapter = new self();
        $mysqlAdapter->setReferenceTable($this->referenceTable, $this->referenceAlias);

        return $mysqlAdapter;
    }

    public function valueCount($fieldName) {
        $this->addGroupBy($fieldName);
        $this->addSelectField($fieldName);
        $this->addSelectField('COUNT(DISTINCT p.id_product) c');
        $this->setLimit(null);
        $this->setOrderField('');
        $results = $this->execute();
        return $results;
    }


    public function useFiltersAsInitialPopulation()
    {
        $this->setLimit(null);
        $this->setOrderField('');
        $this->setSelectFields(['id_product', 'id_manufacturer', 'quantity', 'condition', 'weight', 'price']);
        $query = $this->getQuery();
        $this->setReferenceTable('('.$query.')', 'p');
        $this->resetAllFilters();
    }
}