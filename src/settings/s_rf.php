<?php
// settings/s_rf.php -- HotCRP review field settings object
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Rf_Setting {
    // used by ReviewForm constructor
    public $id;
    public $name;
    public $description;
    public $order;
    public $visibility;
    public $required;
    public $exists_if;
    /** @var list<string> */
    public $values;
    public $start;
    public $flip;
    public $scheme;

    // internal
    public $presence;
    /** @var list<RfValue_Setting> */
    public $xvalues;
}

class RfValue_Setting {
    public $id;
    public $order;
    public $name;
    public $symbol;
}