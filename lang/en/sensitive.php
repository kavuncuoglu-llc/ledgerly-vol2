<?php

// Chapter 51, Topic 7: words a person wrote. A model never writes these, and nothing here is filled in from the customer's message. The holding messages are drafts for the people trained for these conversations
// to review and replace (the support lead for the one on harassment); `reviewed` says who did and when, and the router's tests fail if a holding message has no text.
return [
    'reviewed' => ['by' => null, 'on' => null],

    'holding' => [
        'distress' => 'Thank you for telling us. What you are going through matters more than any invoice, and a person on our team is being brought into this conversation now. If you are in immediate danger, or you might act on thoughts of harming yourself, please contact your local emergency number or a crisis line in your country right away. You do not have to handle this alone.',
        'threat_to_others' => 'Thank you for telling us. A person on our team is being brought into this conversation now, and we are taking what you wrote seriously. If anyone is in immediate danger, please contact your local emergency services right away.',
        'danger' => 'We are bringing a person on our team into this conversation now. If you or anyone near you is in immediate danger, please contact your local emergency services right away, before anything else.',
        'harassment' => 'A person on our team will read this conversation and reply. We want to help with your account, and we ask that everyone in this conversation is treated with respect.',
    ],

    'decline' => [
        'legal' => "I can't give legal advice, and I don't want to guess at something that matters this much. A lawyer can help with this. I can help with how Ledgerly works, such as invoices, payments and late fees. If you would like a person from Ledgerly instead, use the link above.",
        'tax' => "I can't give tax advice. An accountant or your tax authority is the right place for that. I can help with how Ledgerly handles tax on an invoice, such as setting a tax rate. If you would like a person from Ledgerly instead, use the link above.",
        'medical' => "I can't help with medical questions, and a doctor or a local health service is the right place for them. I can help with questions about Ledgerly. If you would like a person from Ledgerly instead, use the link above.",
        'other' => 'That is outside what I can help with. I can answer questions about using Ledgerly, such as invoices, payments, late fees and your account. If you would like a person from Ledgerly instead, use the link above.',
    ],
];
