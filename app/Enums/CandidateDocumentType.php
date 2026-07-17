<?php

namespace App\Enums;

enum CandidateDocumentType: string
{
    case Cv = 'cv';
    case Passport = 'passport';
    case IdentityCard = 'identity_card';
    case DrivingLicense = 'driving_license';
    case LanguageCertificate = 'language_certificate';
    case Qualification = 'qualification';
    case EmploymentReference = 'employment_reference';
    case HealthCertificate = 'health_certificate';
    case PoliceClearance = 'police_clearance';
    case Other = 'other';
}
