<?php

namespace App\Services;

class UtilsService
{

    public function getTvaValue($data)
    {
        if ($data === 0 || $data === 20 || $data === "s_tva_0" || $data === "s_tva_20") {
            return "FR_200";
        } else if ($data === 10 || $data === "s_tva_10") {
            return "FR_100";
        } else if ($data === 5.5 || $data === "s_tva_5.5") {
            return "FR_55";
        } else if ($data === 2.1  || $data === "s_tva_2.1") {
            return "FR_21";
        } else {
            return "FR_200";
        }
    }

    public function trasnformValueTva($data, $value)
    {
        if ($data === 0 || $data === 20 || $data === "s_tva_0" || $data === "s_tva_20") {
            return $value * 1.2;
        } else if ($data === 10 || $data === "s_tva_10") {
            return $value * 1.1;
        } else if ($data === 5.5 || $data === "s_tva_5.5") {
            return $value * 1.055;
        } else if ($data === 2.1  || $data === "s_tva_2.1") {
            return $value * 1.021;
        } else {
            return $value * 1.2;
        }
    }
}
