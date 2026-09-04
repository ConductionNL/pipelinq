---
title: Search Console
sidebar_position: 41
---

# Zoekopdrachten uit Google Search Console

Pipelinq kan de zoekopdrachten die mensen typten voordat ze uw site vonden rechtstreeks uit Google Search Console importeren. De zoekopdrachten staan onder **Marketing, Zoekopdrachten**, naast uw blasts, zodat werving via zoeken naast werving via mail staat.

Er is geen OAuth-flow en geen browserlogin bij Google. Pipelinq gebruikt een **serviceaccount**: een robotidentiteit die u eenmalig aanmaakt in Google Cloud en als gebruiker toevoegt aan de Search Console-property. De dagelijkse import draait daarna vanzelf.

## Wat u nodig heeft

- Een Google Search Console-property voor de site (een URL-prefix zoals `https://example.org/` of een domeinproperty zoals `sc-domain:example.org`), waarvan u eigenaar of volledige gebruiker bent.
- Een Google Cloud-project. Een bestaand project volstaat; een nieuw project kost niets.
- Beheerdersrechten in Pipelinq.

## Stap 1: maak het serviceaccount aan

1. Open de [Google Cloud-console](https://console.cloud.google.com/) en kies het project.
2. Schakel onder **API's en services, Bibliotheek** de **Google Search Console API** in.
3. Klik onder **IAM en beheer, Serviceaccounts** op **Serviceaccount maken**. Geef het een naam zoals `pipelinq-search-console`. Projectrollen zijn niet nodig.
4. Open het nieuwe account, ga naar **Sleutels** en kies **Sleutel toevoegen, Nieuwe sleutel maken, JSON**. Er wordt een bestand gedownload. Dat bestand is de sleutel die Pipelinq nodig heeft; behandel het als een wachtwoord.
5. Noteer het e-mailadres van het account. Het ziet eruit als `pipelinq-search-console@uw-project.iam.gserviceaccount.com`.

## Stap 2: geef het account toegang tot de property

1. Open [Search Console](https://search.google.com/search-console) en kies de property.
2. Klik onder **Instellingen, Gebruikers en rechten** op **Gebruiker toevoegen**.
3. Vul het e-mailadres van het serviceaccount in en kies **Volledig**. Beperkt werkt ook, maar Volledig is wat Google voor de Search Analytics API documenteert.

Herhaal dit voor elke property die u wilt importeren.

## Stap 3: koppel Pipelinq

1. Open **Beheerdersinstellingen, Pipelinq** en zoek de sectie **Marketingverkeer**.
2. Vul onder **Search Console-properties** één property per regel in, precies zoals Search Console die spelt: `https://example.org/` met de slash aan het einde, of `sc-domain:example.org`.
3. Plak de inhoud van het gedownloade JSON-bestand in **Serviceaccountsleutel (JSON)** en sla op.

Na het opslaan toont de sectie het e-mailadres van het serviceaccount, zodat u kunt controleren of het het adres is dat u op de property heeft toegevoegd. De sleutel zelf wordt versleuteld opgeslagen en nooit meer getoond, niet in de instellingen en niet via de API. Om hem te vervangen plakt u een nieuwe. Om hem te verwijderen klikt u op **Opgeslagen sleutel verwijderen**.

## Wat er wordt geïmporteerd

Elke dag leest de import de laatste drie dagen voor elke property: één rij per dag, zoekopdracht en pagina, met klikken, vertoningen, doorklikratio en gemiddelde positie. Search Console publiceert een dag ongeveer twee dagen later en kan die nog bijstellen; daarom worden dezelfde dagen bij de volgende run opnieuw gelezen. Er wordt niets dubbel geteld: een rij wordt één keer per property, dag, zoekopdracht en pagina opgeslagen en bijgewerkt als Google de cijfers bijstelt.

De pagina **Zoekopdrachten** toont de zoekopdrachten met de meeste klikken over de laatste 7, 28 of 90 dagen, met klikken en vertoningen opgeteld, de doorklikratio opnieuw berekend uit die totalen en de gemiddelde positie gewogen naar vertoningen.

## De import handmatig draaien

Een beheerder met shelltoegang kan de import draaien zonder op de dagelijkse taak te wachten, bijvoorbeeld direct na het koppelen van een property:

```bash
occ pipelinq:marketing:search-console:import --days=30
```

Het commando meldt hoeveel rijen het per property importeerde en noemt een property die Google weigerde. De meest voorkomende weigering is een rechtenfout: het serviceaccount is dan nog geen gebruiker op die property.

## Problemen oplossen

- **"User does not have sufficient permission for site"**: het e-mailadres van het serviceaccount staat niet op de property, of de property is in Pipelinq anders gespeld dan in Search Console. Een URL-prefixproperty heeft de slash aan het einde nodig.
- **De pagina blijft na een dag leeg**: kijk naar de tijd van de laatste import in de instellingensectie. Is die leeg, dan heeft de dagelijkse taak nog niet gedraaid; is die recent, dan heeft Google de dagen in de periode nog niet gepubliceerd.
- **De sleutel wordt bij opslaan geweigerd**: het bestand moet de JSON-sleutel van een serviceaccount zijn (`"type": "service_account"`), niet een OAuth-client-id-bestand.

## Privacy

Search Console rapporteert alleen aantallen. Er zit geen bezoeker, sessie of IP-adres in de gegevens, en er gaat niets naar Google behalve het verzoek om die aantallen.
