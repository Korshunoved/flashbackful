<?php
 namespace MailPoetVendor\Doctrine\DBAL\Types; if (!defined('ABSPATH')) exit; use DateTime; use DateTimeInterface; use MailPoetVendor\Doctrine\DBAL\Platforms\AbstractPlatform; use function date_create; class DateTimeType extends \MailPoetVendor\Doctrine\DBAL\Types\Type implements \MailPoetVendor\Doctrine\DBAL\Types\PhpDateTimeMappingType { public function getName() { return \MailPoetVendor\Doctrine\DBAL\Types\Type::DATETIME; } public function getSQLDeclaration(array $fieldDeclaration, \MailPoetVendor\Doctrine\DBAL\Platforms\AbstractPlatform $platform) { return $platform->getDateTimeTypeDeclarationSQL($fieldDeclaration); } public function convertToDatabaseValue($value, \MailPoetVendor\Doctrine\DBAL\Platforms\AbstractPlatform $platform) { if ($value === null) { return $value; } if ($value instanceof \DateTimeInterface) { return $value->format($platform->getDateTimeFormatString()); } throw \MailPoetVendor\Doctrine\DBAL\Types\ConversionException::conversionFailedInvalidType($value, $this->getName(), ['null', 'DateTime']); } public function convertToPHPValue($value, \MailPoetVendor\Doctrine\DBAL\Platforms\AbstractPlatform $platform) { if ($value === null || $value instanceof \DateTimeInterface) { return $value; } $val = \DateTime::createFromFormat($platform->getDateTimeFormatString(), $value); if (!$val) { $val = \date_create($value); } if (!$val) { throw \MailPoetVendor\Doctrine\DBAL\Types\ConversionException::conversionFailedFormat($value, $this->getName(), $platform->getDateTimeFormatString()); } return $val; } } 