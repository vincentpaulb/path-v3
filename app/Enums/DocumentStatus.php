<?php
namespace App\Enums;
enum DocumentStatus:string { case COMPLETE='Complete'; case INCOMPLETE='Incomplete'; public static function values():array{return array_column(self::cases(),'value');} }
