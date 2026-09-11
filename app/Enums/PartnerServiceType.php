<?php

namespace App\Enums;

enum PartnerServiceType: string
{
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
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }

    /** @return list<string> */
    public function allowedArtifactKinds(): array
    {
        return match ($this) {
            self::LanguageCourse => ['enrolment', 'attendance', 'certificate'],
            self::Recognition, self::Translation => ['source_document', 'translation', 'application', 'agency_decision'],
            self::HealthInsurance => ['application', 'membership_confirmation'],
            self::Housing => ['requirement', 'offer', 'booking_confirmation'],
            self::Travel => ['itinerary', 'offer', 'booking_confirmation'],
            self::Tax => ['checklist', 'application', 'authority_confirmation'],
            self::Bank => ['checklist', 'appointment_confirmation'],
            self::Connectivity => ['offer', 'order_confirmation'],
            self::Payroll => ['checklist', 'employee_manifest', 'mapping_report', 'validation_report', 'transfer_receipt'],
        };
    }
}
