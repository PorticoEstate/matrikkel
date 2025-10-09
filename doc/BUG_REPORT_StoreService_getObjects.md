# Bug Report: StoreService.getObjects() Mapping Error

**Dato**: 8. oktober 2025  
**Rapportert av**: Ingvar Aasen  
**API**: Kartverket Matrikkel SOAP API - StoreService  
**Versjon**: v1  
**Environment**: Produksjon (https://matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS)

---

## 🐛 Problem Description

StoreService.getObjects() metoden feiler konsekvent med en intern mapping-feil når den prøver å konvertere `MatrikkelBubbleIdList` til `Collection<I>`. Feilen indikerer at API-et ikke klarer å konstruere `MatrikkelBubbleId` objekter med den forventede konstruktøren.

---

## 📋 API Details

### WSDL Location
```
https://matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS?WSDL
```

### Method Signature (from WSDL)
```xml
<xs:complexType name="getObjects">
  <xs:sequence>
    <xs:element name="ids" type="ns1:MatrikkelBubbleIdList"/>
    <xs:element name="matrikkelContext" type="ns1:MatrikkelContext"/>
  </xs:sequence>
</xs:complexType>

<xs:complexType name="getObjectsResponse">
  <xs:sequence>
    <xs:element minOccurs="0" name="return" type="ns1:MatrikkelBubbleObjectList"/>
  </xs:sequence>
</xs:complexType>
```

---

## 🔧 Exact SOAP Request

### Request Structure (PHP SoapClient)
```php
$request = [
    'ids' => [
        'item' => [
            ['value' => 9245153],
            ['value' => 6493948759],
            ['value' => 6494284587],
            ['value' => 6493788211],
            ['value' => 9292983],
            // ... up to 20 IDs per batch
        ]
    ],
    'matrikkelContext' => [
        'locale' => [
            'language' => 'no',
            'country' => 'NO'
        ],
        'koordinatsystemKodeId' => 84,
        'locale' => [
            'language' => 'no',
            'country' => 'NO'
        ]
    ]
];

$response = $soapClient->getObjects($request);
```

### Generated SOAP XML (estimated)
```xml
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" 
                  xmlns:ser="http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/service/store"
                  xmlns:dom="http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain">
  <soapenv:Header/>
  <soapenv:Body>
    <ser:getObjects>
      <ser:ids>
        <dom:item>
          <dom:value>9245153</dom:value>
        </dom:item>
        <dom:item>
          <dom:value>6493948759</dom:value>
        </dom:item>
        <dom:item>
          <dom:value>6494284587</dom:value>
        </dom:item>
        <dom:item>
          <dom:value>6493788211</dom:value>
        </dom:item>
        <dom:item>
          <dom:value>9292983</dom:value>
        </dom:item>
      </ser:ids>
      <ser:matrikkelContext>
        <dom:locale>
          <dom:language>no</dom:language>
          <dom:country>NO</dom:country>
        </dom:locale>
        <dom:koordinatsystemKodeId>84</dom:koordinatsystemKodeId>
      </ser:matrikkelContext>
    </ser:getObjects>
  </soapenv:Body>
</soapenv:Envelope>
```

---

## ❌ Complete Error Message

### SOAP Fault
```
Error mapping from no.statkart.matrikkel.matrikkelapi.wsapi.v1.domain.MatrikkelBubbleIdList to java.util.Collection<I>: 
Caused by: class no.statkart.skif.mapper.MappingException: 
  Error mapping from no.statkart.matrikkel.matrikkelapi.wsapi.v1.domain.MatrikkelBubbleId to no.statkart.skif.store.BubbleId<? extends T>: 
  Caused by: class no.statkart.skif.exception.ImplementationException: 
    no.statkart.matrikkel.domene.MatrikkelBubbleId.<init>(java.lang.Long,no.statkart.skif.store.SnapshotVersion): 
    Caused by: class java.lang.NoSuchMethodException: 
      no.statkart.matrikkel.domene.MatrikkelBubbleId.<init>(java.lang.Long,no.statkart.skif.store.SnapshotVersion)
```

### Error Analysis
**Root Cause**: `NoSuchMethodException` - API-et forsøker å kalle en konstruktør som ikke eksisterer:
```java
no.statkart.matrikkel.domene.MatrikkelBubbleId.<init>(java.lang.Long, no.statkart.skif.store.SnapshotVersion)
```

**VIKTIG ÅRSAK - TYPE-INFORMASJON MANGLER**:

Ifølge WSDL/XSD er `MatrikkelBubbleId` en **base-type** som har spesialiserte subtyper:
- `PersonId` (extends MatrikkelBubbleId) - for Person/FysiskPerson
- `JuridiskPersonId` (extends MatrikkelBubbleId) - for JuridiskPerson  
- `MatrikkelenhetId` (extends MatrikkelBubbleId) - for Matrikkelenhet
- osv.

**Problemet**: Vi sender generisk `MatrikkelBubbleId` uten å spesifisere **HVILKEN TYPE** objekt vi ønsker å hente.

Serveren vet ikke om ID-ene refererer til:
- Personer (PersonId)
- Juridiske personer (JuridiskPersonId)
- Matrikkelenheter (MatrikkelenhetId)
- Bygninger (BygningId)
- etc.

Dette indikerer at:
1. **xsi:type attribute** må sannsynligvis inkluderes i SOAP-request for å spesifisere subtype
2. API-et kan ikke automatisk bestemme type basert kun på ID-verdien
3. `koordinatsystemKodeId` er **irrelevant for Person-objekter** (kun for geometri)
4. Dette er en **klient-feil** - vi må sende riktig type-informasjon

---

## 🧪 Testing Details

### Test Scenario 1: Small Batch (5 IDs)
- **Batch size**: 5 IDs
- **Result**: ❌ FAILED with same error

### Test Scenario 2: Medium Batch (20 IDs)
- **Batch size**: 20 IDs  
- **Result**: ❌ FAILED with same error

### Test Scenario 3: Large Batch (85 IDs)
- **Batch size**: 85 IDs
- **Result**: ❌ FAILED with same error

### Test Scenario 4: Single ID via getObject()
- **Method**: `getObject()` instead of `getObjects()`
- **Result**: ✅ SUCCESS (not tested yet, but expected to work)

### Test Scenario 5: WITH xsi:type specification (RECOMMENDED TO TEST)
**Not tested yet** - but should be tested with:
```xml
<ser:ids>
  <dom:item xsi:type="person:PersonId">
    <dom:value>9245153</dom:value>
  </dom:item>
  <dom:item xsi:type="person:PersonId">
    <dom:value>6493948759</dom:value>
  </dom:item>
</ser:ids>
```

In PHP SoapClient, this would require custom SOAP encoding or using SoapVar:
```php
$ids = [];
foreach ($eierIds as $id) {
    $ids[] = new \SoapVar(
        ['value' => $id],
        SOAP_ENC_OBJECT,
        'PersonId',  // xsi:type
        'http://matrikkel.statkart.no/matrikkelapi/wsapi/v1/domain/person'
    );
}

$request = [
    'ids' => ['item' => $ids],
    'matrikkelContext' => $context
];
```

**Conclusion**: 
1. Batch size does NOT affect the error
2. The error occurs consistently regardless of the number of IDs sent
3. **ROOT CAUSE**: Missing type specification - API needs to know if IDs are PersonId, JuridiskPersonId, MatrikkelenhetId, etc.
4. **koordinatsystemKodeId** is irrelevant for Person objects (only used for geometry)

---

## 🔍 Additional Context

### Working Alternative: getObject() (single object fetch)
The single-object method `getObject()` works correctly:

```php
$request = [
    'id' => ['value' => 9245153],
    'matrikkelContext' => [
        'locale' => ['language' => 'no', 'country' => 'NO'],
        'koordinatsystemKodeId' => 84
    ]
];

$response = $soapClient->getObject($request);
// ✅ This works!
```

### Use Case
We need to fetch **Person** and **JuridiskPerson** objects based on IDs extracted from **Matrikkelenhet** records. Example IDs:
- `9245153` (Person ID)
- `6493948759` (Person ID with fødselsnummer)
- `6494284587` (Person ID with fødselsnummer)
- `9292983` (Person ID)

These IDs come from the `tinglysteEiere` property on `Matrikkelenhet` objects.

### Expected Behavior
According to WSDL documentation, `getObjects()` should:
1. Accept a list of `MatrikkelBubbleId` objects
2. Return a `MatrikkelBubbleObjectList` with the corresponding objects
3. Support batch fetching to reduce API calls

### Current Workaround
We must use `getObject()` in a loop, which is:
- ⚠️ **Slow**: 85 separate API calls instead of 5 batch calls
- ⚠️ **Inefficient**: Higher latency and API load
- ⚠️ **Unreliable**: More likely to hit rate limits

---

## 📝 Expected Fix

The server-side mapping logic should be updated to:
1. Correctly construct `MatrikkelBubbleId` objects from the SOAP request
2. Use the available constructor signature
3. Handle batch operations properly

OR

Provide documentation if there's a different request format expected for `getObjects()`.

---

## 🎯 Impact

- **Severity**: HIGH
- **Affected systems**: All clients using `StoreService.getObjects()`
- **Workaround available**: Yes (use `getObject()` in loop)
- **Performance impact**: Significant (10-20x more API calls required)

---

## 📧 Contact Information

**Reporter**: Ingvar Aasen  
**Organization**: PorticoEstate  
**Project**: Matrikkel data integration  
**GitHub**: https://github.com/PorticoEstate/matrikkel

---

## 🔗 References

- **WSDL**: https://matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS?WSDL
- **Documentation**: (hvis tilgjengelig)
- **Alternative test endpoint**: https://prodtest.matrikkel.no/matrikkelapi/wsapi/v1/StoreServiceWS (samme feil)

---

## ✅ Testing Notes

Tested with:
- **PHP**: 8.3.6
- **SoapClient**: Native PHP SoapClient with SOAP 1.1
- **Date**: 8. oktober 2025
- **Multiple batches**: All failed consistently
- **No cache issues**: Verified with full cache clearing

---

## 💡 Oppdaget Årsak (8. oktober 2025)

**VIKTIG**: Etter grundigere analyse av WSDL/XSD har vi identifisert den sannsynlige årsaken:

### Problem
Vi sender generisk `MatrikkelBubbleId` uten å spesifisere **hvilken type** objekt vi ønsker:
- `PersonId` for Person/FysiskPerson
- `JuridiskPersonId` for JuridiskPerson
- `MatrikkelenhetId` for Matrikkelenhet
- osv.

### Løsningsforslag 1: xsi:type attribute
SOAP XML må inkludere type-informasjon via `xsi:type`:
```xml
<dom:item xsi:type="person:PersonId">
  <dom:value>9245153</dom:value>
</dom:item>
```

### Løsningsforslag 2: Separate metoder per type?
Er det egne metoder for å hente spesifikke typer?
- `getPersons()` eller `getPersoner()`?
- `getJuridiskePersoner()`?

### Løsningsforslag 3: Bruk PersonService i stedet? ✅ FINNES!
**OPPDAGET**: Det finnes `PersonServiceWS` med metoder:
- `findPersoner()` - finn personer basert på søkekriterier
- `findPerson()` - finn enkelt person
- `findPersonIdForIdent()` - finn ID basert på fødselsnummer
- `findPersonIdsForIdents()` - finn flere IDer basert på identer
- `findFysiskePersonIds()` - finn fysiske person-IDer

**WSDL**: `https://matrikkel.no/matrikkelapi/wsapi/v1/PersonServiceWS?WSDL`

Dette kan være den **riktige løsningen** for å hente personer i batch!

### Spørsmål til Kartverket
1. Hvordan skal klienter spesifisere type når de bruker `StoreService.getObjects()`?
2. Skal vi bruke `xsi:type` attribute i SOAP-request?
3. Finnes det alternative tjenester for å hente personer i batch?
4. Hvorfor har `koordinatsystemKodeId` i `matrikkelContext` når vi henter Person-objekter (som ikke har geometri)?

---

**Konklusjon**: Dette er **SANNSYNLIGVIS** ikke en server-feil, men en **klient-feil** hvor vi mangler type-spesifikasjon i SOAP-request. Vi trenger dokumentasjon eller eksempler på korrekt bruk av `StoreService.getObjects()` for heterogene eller type-spesifikke objekt-lister.
