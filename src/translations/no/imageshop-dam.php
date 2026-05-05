<?php
/**
 * Imageshop plugin for Craft CMS 3.x
 *
 * Imageshop Integration for CraftCMS
 *
 * @link      https://webdna.co.uk
 * @copyright Copyright (c) 2022 WebDNA
 */

/**
 * @author    WebDNA
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

    // Utility
    'Imageshop' => 'Imageshop',
    'Imageshop DAM' => 'Imageshop DAM',
    'When you press this button, Imageshop will update all metadata transferred to Craft from Imageshop, such as alternative text.' => 'Når du trykker på denne knappen, vil Imageshop oppdatere all metadata overført til Craft fra Imageshop, som alternativ tekst.',
    'Important: This action cannot be undone.' => 'Viktig: Denne handlingen kan ikke angres.',
    'Sync metadata' => 'Synkroniser metadata',

    // Queue jobs
    'Re-syncing imageshop data {index} of {count}' => 'Synkroniserer imageshop-data {index} av {count}',
    'Getting recently changed Imageshop DAM documents' => 'Henter nylig endrede Imageshop DAM-dokumenter',
];
