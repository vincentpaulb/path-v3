<?php
namespace App\Enums;
enum UserRole:string { case ADMIN='Admin'; case REGULAR='Regular'; case TOD='TOD'; case PSTO='PSTO'; case HEAD='Head'; case EVALUATOR='Evaluator'; case CASHIER='Cashier'; public static function values():array{return array_column(self::cases(),'value');} }
