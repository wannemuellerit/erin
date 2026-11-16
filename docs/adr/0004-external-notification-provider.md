# ADR 0004: Austauschbarer SMS-/WhatsApp-Transport

Status: akzeptiert für eine deaktivierte Pilotintegration

Erin bindet SMS und WhatsApp über `ExternalMessageProvider` an. Der erste Adapter ist Twilio, weil beide Kanäle, Zustellwebhooks, WhatsApp-Templates, Länderabdeckung und Kostenmetadaten über eine API verfügbar sind. Produktcode, Einwilligungen, Zustellledger und Webhooks bleiben providerunabhängig; ein Anbieterwechsel erfordert nur einen neuen Adapter.

Die Integration ist standardmäßig global ausgeschaltet. Aktivierung erfordert AVV-/Subprozessor-, Datenregions-, Länder-, Sender-/Template- und DPO-Freigabe sowie `TWILIO_ENABLED=true`. Nummern werden verschlüsselt, nur gehashte Werte sind suchbar. Inhalte stammen aus einer festen, datensparsamen Ereignis-Matrix und enthalten keine Bewerbungs-, Dokument-, Visa- oder Zahlungsdetails. STOP/Widerruf wird vor jedem Queueversand erneut geprüft.
