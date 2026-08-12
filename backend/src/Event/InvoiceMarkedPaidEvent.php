<?php

namespace App\Event;

use App\Entity\Invoice;
use Symfony\Contracts\EventDispatcher\Event;

/** architecture doc §6.9 / functional requirements §8.1: "the Member is notified." */
final class InvoiceMarkedPaidEvent extends Event
{
    public const NAME = 'invoice.marked_paid';

    public function __construct(private readonly Invoice $invoice)
    {
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }
}
