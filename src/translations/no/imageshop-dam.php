<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://www.imageshop.org
 * @copyright Copyright (c) 2022 Imageshop
 */

/**
 * @author    Imageshop
 * @package   Imageshop
 * @since     2.0.0
 */
return [
    'Imageshop plugin loaded' => 'Imageshop-tillegg lastet',
    'buttonText' => 'Velg bilde',

    // Plugin settings
    'Token' => 'Token',
    'Imageshop token' => 'Imageshop-token',
    'Key' => 'Nøkkel',
    'Imageshop private key' => 'Imageshop privatnøkkel',
    'Norwegian' => 'Norsk',
    'English' => 'Engelsk',
    'Language' => 'Språk',
    'Select' => 'Velg',
    'Imageshop field used to generate opengraph image' => 'Imageshop-felt brukt til å generere opengraph-bilde',
    'This will work only if the SEOmatic plugin is installed. The field must be assigned to the element (such as an entry) for which you want to generate the OpenGraph image using Imageshop. The first image from the field will be used.' => 'Dette fungerer kun hvis SEOmatic-tillegget er installert. Feltet må være tilordnet elementet (f.eks. en oppføring) du vil generere OpenGraph-bildet for med Imageshop. Det første bildet fra feltet vil bli brukt.',
    'Global set which will be used as source for the default opengraph image.' => 'Globalt sett som brukes som kilde for standard opengraph-bilde.',
    'This will work only if the SEOmatic plugin is installed. This global must have imageshop field assigned and will be used only if current element does not have its own specific opengraph defined by its Imageshop field.' => 'Dette fungerer kun hvis SEOmatic-tillegget er installert. Dette globale settet må ha et Imageshop-felt tilordnet, og brukes kun hvis gjeldende element ikke har sitt eget opengraph-bilde definert via Imageshop-feltet.',

    // Field settings
    'Sizes' => 'Størrelser',
    'Predefined sizes the user can choose from.' => 'Forhåndsdefinerte størrelser brukeren kan velge mellom.',
    'Show Crop Dialogue?' => 'Vis beskjæringsdialog?',
    'Indicates whether the crop dialogue should be shown.' => 'Angir om beskjæringsdialogen skal vises.',
    'Show Size Dialogue?' => 'Vis størrelsesdialog?',
    'Indicates whether the size dialogue should be shown.' => 'Angir om størrelsesdialogen skal vises.',
    'Edit description before insert?' => 'Rediger beskrivelse før innsetting?',
    'Make it possible to edit the description before the image is inserted. Recommended to be off.' => 'Gjør det mulig å redigere beskrivelsen før bildet settes inn. Anbefalt å ha av.',
    'Show Credits?' => 'Vis kreditering?',
    'Indicates whether the credits should be shown and editable' => 'Angir om krediteringen skal vises og være redigerbar',
    'Allow multiple?' => 'Tillat flere?',
    'Indicates whether the field should allow multiple images' => 'Angir om feltet skal tillate flere bilder',

    // Field input
    'Reorder' => 'Sorter',
    'Show settings' => 'Vis innstillinger',
    'Remove image' => 'Fjern bilde',
    'Alternative text' => 'Alternativ tekst',
    'Description' => 'Beskrivelse',
    'Leave a field empty to use the text from Imageshop. Text entered here is kept when metadata is synced.' => 'La et felt stå tomt for å bruke teksten fra Imageshop. Tekst du skriver inn her beholdes når metadata synkroniseres.',

    // Utility
    'Imageshop' => 'Imageshop',
    'Imageshop DAM' => 'Imageshop DAM',
    'This will fetch the latest metadata from the Imageshop API and update all Imageshop fields in Craft, including alternative text, descriptions, credits, rights, tags and titles. Elements are saved through Craft, so caches and the search index are refreshed.' => 'Dette henter siste metadata fra Imageshop-API-et og oppdaterer alle Imageshop-felt i Craft, inkludert alternativ tekst, beskrivelser, kreditering, rettigheter, emneord og titler. Elementene lagres gjennom Craft, så cache og søkeindeks oppdateres.',
    'Alternative text and descriptions entered in Craft are kept as local overrides and are not overwritten.' => 'Alternativ tekst og beskrivelser skrevet inn i Craft beholdes som lokale overstyringer og blir ikke overskrevet.',
    'To keep metadata in sync automatically, schedule `php craft imageshop-dam/sync/run --inline` with cron.' => 'For å holde metadata synkronisert automatisk, kjør `php craft imageshop-dam/sync/run --inline` fra cron.',
    'Sync metadata' => 'Synkroniser metadata',
    'Sync history' => 'Synkroniseringshistorikk',
    'No syncs have been run yet.' => 'Ingen synkroniseringer er kjørt ennå.',
    'Date' => 'Dato',
    'Documents changed' => 'Dokumenter endret',
    'Elements' => 'Elementer',
    'Status' => 'Status',
    'Success' => 'Vellykket',
    'No changes' => 'Ingen endringer',
    'Partial, will retry' => 'Delvis, prøves igjen',
    'Failed, API error' => 'Mislyktes, API-feil',
    'The Imageshop API request failed. Nothing was changed; check the Craft logs and try again later.' => 'Forespørselen til Imageshop-API-et feilet. Ingenting ble endret; sjekk Craft-loggene og prøv igjen senere.',
    'Some Imageshop requests failed. {count} sync {count, plural, =1{job was} other{jobs were}} queued for what could be fetched; run the sync again to retry the rest.' => 'Noen Imageshop-forespørsler feilet. {count} synkroniseringsjobb{count, plural, =1{} other{er}} ble lagt i kø for det som kunne hentes; kjør synkroniseringen på nytt for resten.',
    'Document' => 'Dokument',
    'Queued {count} sync {count, plural, =1{job} other{jobs}}. Check the queue to monitor progress.' => 'La {count} synkroniseringsjobb{count, plural, =1{} other{er}} i køen. Følg med i køen for fremdrift.',
    'No changes found. All metadata is up to date.' => 'Ingen endringer funnet. All metadata er oppdatert.',

    // Queue jobs
    'Re-syncing imageshop data {index} of {count}' => 'Synkroniserer imageshop-data {index} av {count}',
    'Getting recently changed Imageshop DAM documents' => 'Henter nylig endrede Imageshop DAM-dokumenter',
];
