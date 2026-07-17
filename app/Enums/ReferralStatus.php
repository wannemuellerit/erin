<?php

namespace App\Enums;

enum ReferralStatus: string
{
    case Clicked = 'clicked';
    case Registered = 'registered';
    case Applied = 'applied';
    case Hired = 'hired';
    case Holding = 'holding';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';
}
