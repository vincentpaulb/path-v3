<?php
namespace App\Enums;
enum Province:string { case CEBU='Cebu'; case SIQUIJOR='Siquijor'; case NEGROS='Negros'; case BOHOL='Bohol'; public static function values():array{return array_column(self::cases(),'value');} }
