<?php

namespace App\Enums;

enum CountryOperation: string
{
    case Recruiting = 'recruiting';
    case EmploymentContract = 'employment_contract';
    case Billing = 'billing';
    case Visa = 'visa';
    case Relocation = 'relocation';
    case LanguageCourse = 'language_course';
    case Recognition = 'recognition';
    case Translation = 'translation';
    case HealthInsurance = 'health_insurance';
    case Housing = 'housing';
    case Travel = 'travel';
    case Tax = 'tax';
    case Bank = 'bank';
    case Connectivity = 'connectivity';
    case Payroll = 'payroll';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $item): string => $item->value, self::cases());
    }
}
