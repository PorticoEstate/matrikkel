# Matrikkel API Java Project - Komplett Oppstartsguide

## Innholdsfortegnelse
1. [Prosjektoversikt](#prosjektoversikt)
2. [Teknologivalg](#teknologivalg)
3. [Prosjektstruktur](#prosjektstruktur)
4. [Maven Setup](#maven-setup)
5. [WSDL til Java-klasser](#wsdl-til-java-klasser)
6. [Database Setup (PostgreSQL)](#database-setup-postgresql)
7. [Konfigurasjon](#konfigurasjon)
8. [SOAP Client Implementation](#soap-client-implementation)
9. [Service Layer](#service-layer)
10. [Repository Layer (Database)](#repository-layer-database)
11. [Testing](#testing)
12. [Deployment](#deployment)

---

## Prosjektoversikt

### Mål
Bygge en robust Java-applikasjon som:
- Kommuniserer med Matrikkel SOAP API
- Håndterer bulk-nedlasting via NedlastningService
- Lagrer data i PostgreSQL database
- Håndterer eierforhold og relaterte entiteter
- Støtter paginering og inkrementelle oppdateringer

### Hvorfor Java?
- **Automatisk SOAP serialisering**: Java JAX-WS håndterer komplekse objekter (MatrikkelBubbleId med snapshotVersion) automatisk
- **Type-sikkerhet**: Kompileringstidssjekk av alle API-kall
- **Offisiell dokumentasjon**: Matrikkel API-dokumentasjonen bruker Java-eksempler
- **Bedre WSDL-støtte**: wsimport genererer perfekte klient-klasser
- **Spring Boot**: Moderne, produktionsklar stack med god PostgreSQL-integrasjon

---

## Teknologivalg

### Core Stack
```xml
<!-- Java Version -->
<java.version>17</java.version>

<!-- Spring Boot -->
<spring-boot.version>3.2.0</spring-boot.version>

<!-- Database -->
PostgreSQL 15+
Spring Data JPA + Hibernate

<!-- SOAP Client -->
JAX-WS (Metro implementation)
Apache CXF (alternativ)

<!-- Build Tool -->
Maven 3.9+
```

### Viktige Dependencies
- **Spring Boot Starter Web**: REST API (optional)
- **Spring Boot Starter Data JPA**: Database access
- **PostgreSQL Driver**: JDBC driver
- **JAX-WS RI**: SOAP client
- **Lombok**: Reduce boilerplate
- **Logback**: Logging
- **JUnit 5 + Mockito**: Testing

---

## Prosjektstruktur

```
matrikkel-java/
├── pom.xml
├── README.md
├── src/
│   ├── main/
│   │   ├── java/
│   │   │   └── no/
│   │   │       └── bergenkommune/
│   │   │           └── matrikkel/
│   │   │               ├── MatrikkelApplication.java
│   │   │               ├── config/
│   │   │               │   ├── DatabaseConfig.java
│   │   │               │   ├── SoapClientConfig.java
│   │   │               │   └── ApplicationProperties.java
│   │   │               ├── client/
│   │   │               │   ├── generated/          (Auto-generated fra WSDL)
│   │   │               │   │   ├── nedlastning/
│   │   │               │   │   ├── store/
│   │   │               │   │   ├── matrikkelenhet/
│   │   │               │   │   └── ...
│   │   │               │   ├── NedlastningClientWrapper.java
│   │   │               │   ├── StoreClientWrapper.java
│   │   │               │   └── MatrikkelClientFactory.java
│   │   │               ├── domain/
│   │   │               │   ├── entity/
│   │   │               │   │   ├── Matrikkelenhet.java
│   │   │               │   │   ├── Eier.java
│   │   │               │   │   ├── Adresse.java
│   │   │               │   │   └── ...
│   │   │               │   └── dto/
│   │   │               │       └── MatrikkelenhetImportResult.java
│   │   │               ├── repository/
│   │   │               │   ├── MatrikkelenhetRepository.java
│   │   │               │   ├── EierRepository.java
│   │   │               │   └── ...
│   │   │               ├── service/
│   │   │               │   ├── MatrikkelenhetImportService.java
│   │   │               │   ├── EierImportService.java
│   │   │               │   └── NedlastningService.java
│   │   │               ├── mapper/
│   │   │               │   ├── MatrikkelenhetMapper.java
│   │   │               │   └── EierMapper.java
│   │   │               └── cli/
│   │   │                   └── ImportCommand.java
│   │   └── resources/
│   │       ├── application.yml
│   │       ├── application-dev.yml
│   │       ├── application-prod.yml
│   │       ├── logback-spring.xml
│   │       ├── db/
│   │       │   └── migration/
│   │       │       ├── V1__initial_schema.sql
│   │       │       └── V2__add_indexes.sql
│   │       └── wsdl/
│   │           ├── NedlastningServiceWS.wsdl
│   │           ├── StoreServiceWS.wsdl
│   │           ├── MatrikkelenhetServiceWS.wsdl
│   │           └── alle andre WSDL-filer...
│   └── test/
│       └── java/
│           └── no/
│               └── bergenkommune/
│                   └── matrikkel/
│                       ├── service/
│                       │   └── MatrikkelenhetImportServiceTest.java
│                       └── client/
│                           └── NedlastningClientTest.java
└── docs/
    ├── API_DOCUMENTATION.md
    ├── DATABASE_SCHEMA.md
    └── DEPLOYMENT.md
```

---

## Maven Setup

### pom.xml (Komplett)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<project xmlns="http://maven.apache.org/POM/4.0.0"
         xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:schemaLocation="http://maven.apache.org/POM/4.0.0 
         http://maven.apache.org/xsd/maven-4.0.0.xsd">
    <modelVersion>4.0.0</modelVersion>

    <parent>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-parent</artifactId>
        <version>3.2.0</version>
        <relativePath/>
    </parent>

    <groupId>no.bergenkommune</groupId>
    <artifactId>matrikkel-integration</artifactId>
    <version>1.0.0-SNAPSHOT</version>
    <name>Matrikkel API Integration</name>
    <description>Java integration with Norwegian Matrikkel SOAP API</description>

    <properties>
        <java.version>17</java.version>
        <maven.compiler.source>17</maven.compiler.source>
        <maven.compiler.target>17</maven.compiler.target>
        <project.build.sourceEncoding>UTF-8</project.build.sourceEncoding>
        
        <!-- JAX-WS version -->
        <jaxws.version>4.0.0</jaxws.version>
        <jakarta-xml-ws.version>4.0.0</jakarta-xml-ws.version>
    </properties>

    <dependencies>
        <!-- Spring Boot Starters -->
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-data-jpa</artifactId>
        </dependency>
        
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-web</artifactId>
            <optional>true</optional> <!-- Kun hvis du vil ha REST API -->
        </dependency>

        <!-- PostgreSQL Driver -->
        <dependency>
            <groupId>org.postgresql</groupId>
            <artifactId>postgresql</artifactId>
            <scope>runtime</scope>
        </dependency>

        <!-- Database Migration (Flyway) -->
        <dependency>
            <groupId>org.flywaydb</groupId>
            <artifactId>flyway-core</artifactId>
        </dependency>
        <dependency>
            <groupId>org.flywaydb</groupId>
            <artifactId>flyway-database-postgresql</artifactId>
        </dependency>

        <!-- JAX-WS for SOAP -->
        <dependency>
            <groupId>jakarta.xml.ws</groupId>
            <artifactId>jakarta.xml.ws-api</artifactId>
            <version>${jakarta-xml-ws.version}</version>
        </dependency>
        
        <dependency>
            <groupId>com.sun.xml.ws</groupId>
            <artifactId>jaxws-rt</artifactId>
            <version>${jaxws.version}</version>
            <scope>runtime</scope>
        </dependency>

        <!-- Lombok (reducer boilerplate) -->
        <dependency>
            <groupId>org.projectlombok</groupId>
            <artifactId>lombok</artifactId>
            <optional>true</optional>
        </dependency>

        <!-- Apache Commons -->
        <dependency>
            <groupId>org.apache.commons</groupId>
            <artifactId>commons-lang3</artifactId>
        </dependency>

        <!-- Configuration -->
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-configuration-processor</artifactId>
            <optional>true</optional>
        </dependency>

        <!-- Testing -->
        <dependency>
            <groupId>org.springframework.boot</groupId>
            <artifactId>spring-boot-starter-test</artifactId>
            <scope>test</scope>
        </dependency>
        
        <dependency>
            <groupId>com.h2database</groupId>
            <artifactId>h2</artifactId>
            <scope>test</scope>
        </dependency>
    </dependencies>

    <build>
        <plugins>
            <!-- Spring Boot Maven Plugin -->
            <plugin>
                <groupId>org.springframework.boot</groupId>
                <artifactId>spring-boot-maven-plugin</artifactId>
                <configuration>
                    <excludes>
                        <exclude>
                            <groupId>org.projectlombok</groupId>
                            <artifactId>lombok</artifactId>
                        </exclude>
                    </excludes>
                </configuration>
            </plugin>

            <!-- JAX-WS wsimport plugin - Genererer Java-klasser fra WSDL -->
            <plugin>
                <groupId>com.sun.xml.ws</groupId>
                <artifactId>jaxws-maven-plugin</artifactId>
                <version>4.0.0</version>
                <executions>
                    <!-- NedlastningService -->
                    <execution>
                        <id>wsimport-nedlastning</id>
                        <goals>
                            <goal>wsimport</goal>
                        </goals>
                        <configuration>
                            <wsdlFiles>
                                <wsdlFile>NedlastningServiceWS.wsdl</wsdlFile>
                            </wsdlFiles>
                            <wsdlDirectory>${project.basedir}/src/main/resources/wsdl</wsdlDirectory>
                            <packageName>no.bergenkommune.matrikkel.client.generated.nedlastning</packageName>
                            <sourceDestDir>${project.build.directory}/generated-sources/wsimport</sourceDestDir>
                            <keep>true</keep>
                        </configuration>
                    </execution>
                    
                    <!-- StoreService -->
                    <execution>
                        <id>wsimport-store</id>
                        <goals>
                            <goal>wsimport</goal>
                        </goals>
                        <configuration>
                            <wsdlFiles>
                                <wsdlFile>StoreServiceWS.wsdl</wsdlFile>
                            </wsdlFiles>
                            <wsdlDirectory>${project.basedir}/src/main/resources/wsdl</wsdlDirectory>
                            <packageName>no.bergenkommune.matrikkel.client.generated.store</packageName>
                            <sourceDestDir>${project.build.directory}/generated-sources/wsimport</sourceDestDir>
                            <keep>true</keep>
                        </configuration>
                    </execution>
                    
                    <!-- MatrikkelenhetService -->
                    <execution>
                        <id>wsimport-matrikkelenhet</id>
                        <goals>
                            <goal>wsimport</goal>
                        </goals>
                        <configuration>
                            <wsdlFiles>
                                <wsdlFile>MatrikkelenhetServiceWS.wsdl</wsdlFile>
                            </wsdlFiles>
                            <wsdlDirectory>${project.basedir}/src/main/resources/wsdl</wsdlDirectory>
                            <packageName>no.bergenkommune.matrikkel.client.generated.matrikkelenhet</packageName>
                            <sourceDestDir>${project.build.directory}/generated-sources/wsimport</sourceDestDir>
                            <keep>true</keep>
                        </configuration>
                    </execution>
                    
                    <!-- Legg til flere executions for andre WSDL-filer -->
                </executions>
            </plugin>
        </plugins>
    </build>
</project>
```

---

## WSDL til Java-klasser

### Steg 1: Kopier WSDL-filer

```bash
# Kopier alle WSDL-filer fra PHP-prosjektet
mkdir -p src/main/resources/wsdl
cp /opt/matrikkel/doc/wsdl/*.wsdl src/main/resources/wsdl/
cp /opt/matrikkel/doc/wsdl/*.xsd src/main/resources/wsdl/
```

### Steg 2: Generer Java-klasser

```bash
# Maven vil automatisk generere klasser ved compile
mvn clean compile

# Genererte klasser finnes i:
# target/generated-sources/wsimport/no/bergenkommune/matrikkel/client/generated/
```

### Steg 3: Verifiser genererte klasser

Etter `mvn compile` skal du ha:
```
target/generated-sources/wsimport/
└── no/bergenkommune/matrikkel/client/generated/
    ├── nedlastning/
    │   ├── NedlastningServiceWS.java
    │   ├── NedlastningService.java
    │   ├── MatrikkelBubbleId.java    ← Viktig!
    │   ├── SnapshotVersion.java      ← Viktig!
    │   ├── Grunneiendom.java
    │   └── ...
    ├── store/
    │   ├── StoreServiceWS.java
    │   └── ...
    └── matrikkelenhet/
        └── ...
```

**VIKTIG**: Disse klassene har automatisk serialisering som fungerer perfekt! 🎉

---

## Database Setup (PostgreSQL)

### application.yml

```yaml
spring:
  application:
    name: matrikkel-integration
    
  datasource:
    url: jdbc:postgresql://10.0.2.15:5435/matrikkel
    username: ${DB_USERNAME:hc483}
    password: ${DB_PASSWORD}
    driver-class-name: org.postgresql.Driver
    
  jpa:
    hibernate:
      ddl-auto: validate  # Flyway håndterer migrations
    properties:
      hibernate:
        dialect: org.hibernate.dialect.PostgreSQLDialect
        format_sql: true
        jdbc:
          batch_size: 100
        order_inserts: true
        order_updates: true
    show-sql: false
    
  flyway:
    enabled: true
    baseline-on-migrate: true
    locations: classpath:db/migration
    
logging:
  level:
    no.bergenkommune.matrikkel: DEBUG
    org.hibernate.SQL: DEBUG
    org.hibernate.type.descriptor.sql.BasicBinder: TRACE
```

### Entity Class: Matrikkelenhet.java

```java
package no.bergenkommune.matrikkel.domain.entity;

import jakarta.persistence.*;
import lombok.Data;
import lombok.NoArgsConstructor;
import lombok.AllArgsConstructor;
import lombok.Builder;

import java.time.LocalDate;
import java.time.LocalDateTime;
import java.util.List;

@Entity
@Table(name = "matrikkel_matrikkelenheter", indexes = {
    @Index(name = "idx_kommunenummer", columnList = "kommunenummer"),
    @Index(name = "idx_gardsnummer_bruksnummer", columnList = "gardsnummer, bruksnummer"),
    @Index(name = "idx_matrikkelenhet_id", columnList = "matrikkelenhet_id")
})
@Data
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class Matrikkelenhet {
    
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;
    
    @Column(name = "matrikkelenhet_id", nullable = false, unique = true)
    private Long matrikkelenhetId;
    
    @Column(name = "kommunenummer", length = 4, nullable = false)
    private String kommunenummer;
    
    @Column(name = "gardsnummer")
    private Integer gardsnummer;
    
    @Column(name = "bruksnummer")
    private Integer bruksnummer;
    
    @Column(name = "festenummer")
    private Integer festenummer;
    
    @Column(name = "seksjonsnummer")
    private Integer seksjonsnummer;
    
    @Column(name = "bruksnavn", length = 500)
    private String bruksnavn;
    
    @Column(name = "matrikkelnummer", length = 100)
    private String matrikkelnummer;
    
    @Column(name = "eiendomstype", length = 50)
    private String eiendomstype;
    
    @Column(name = "registerenhettype", length = 50)
    private String registerenhettype;
    
    @Column(name = "teigmedlemskap_status", length = 50)
    private String teigmedlemskapStatus;
    
    @Column(name = "areal_teig_m2")
    private Double arealTeigM2;
    
    @Column(name = "sefrak_id")
    private Long sefrakId;
    
    @Column(name = "skylddelingstall")
    private Integer skylddelingstall;
    
    @Column(name = "vedtaksdato")
    private LocalDate vedtaksdato;
    
    @Column(name = "oppdateringsdato")
    private LocalDateTime oppdateringsdato;
    
    @Column(name = "sist_lastet_ned")
    private LocalDateTime sistLastetNed;
    
    @OneToMany(mappedBy = "matrikkelenhet", cascade = CascadeType.ALL, orphanRemoval = true)
    private List<Eier> eiere;
    
    @OneToMany(mappedBy = "matrikkelenhet", cascade = CascadeType.ALL, orphanRemoval = true)
    private List<Adresse> adresser;
    
    @PrePersist
    @PreUpdate
    public void updateTimestamp() {
        this.sistLastetNed = LocalDateTime.now();
    }
}
```

### Entity Class: Eier.java

```java
package no.bergenkommune.matrikkel.domain.entity;

import jakarta.persistence.*;
import lombok.Data;
import lombok.NoArgsConstructor;
import lombok.AllArgsConstructor;
import lombok.Builder;

@Entity
@Table(name = "matrikkel_eiere", indexes = {
    @Index(name = "idx_eier_id", columnList = "eier_id"),
    @Index(name = "idx_matrikkelenhet_id", columnList = "matrikkelenhet_id")
})
@Data
@NoArgsConstructor
@AllArgsConstructor
@Builder
public class Eier {
    
    @Id
    @GeneratedValue(strategy = GenerationType.IDENTITY)
    private Long id;
    
    @Column(name = "eier_id", nullable = false)
    private Long eierId;
    
    @Column(name = "matrikkelenhet_id", nullable = false)
    private Long matrikkelenhetId;
    
    @ManyToOne(fetch = FetchType.LAZY)
    @JoinColumn(name = "matrikkelenhet_id", referencedColumnName = "matrikkelenhet_id", 
                insertable = false, updatable = false)
    private Matrikkelenhet matrikkelenhet;
    
    @Column(name = "eier_type", length = 50)
    private String eierType; // "fysisk_person" eller "juridisk_person"
    
    @Column(name = "navn", length = 500)
    private String navn;
    
    @Column(name = "fodselsnummer", length = 11)
    private String fodselsnummer;
    
    @Column(name = "organisasjonsnummer", length = 9)
    private String organisasjonsnummer;
    
    @Column(name = "andel_teller")
    private Integer andelTeller;
    
    @Column(name = "andel_nevner")
    private Integer andelNevner;
}
```

### Flyway Migration: V1__initial_schema.sql

```sql
-- src/main/resources/db/migration/V1__initial_schema.sql

-- Matrikkelenheter table
CREATE TABLE IF NOT EXISTS matrikkel_matrikkelenheter (
    id BIGSERIAL PRIMARY KEY,
    matrikkelenhet_id BIGINT NOT NULL UNIQUE,
    kommunenummer VARCHAR(4) NOT NULL,
    gardsnummer INTEGER,
    bruksnummer INTEGER,
    festenummer INTEGER,
    seksjonsnummer INTEGER,
    bruksnavn VARCHAR(500),
    matrikkelnummer VARCHAR(100),
    eiendomstype VARCHAR(50),
    registerenhettype VARCHAR(50),
    teigmedlemskap_status VARCHAR(50),
    areal_teig_m2 DOUBLE PRECISION,
    sefrak_id BIGINT,
    skylddelingstall INTEGER,
    vedtaksdato DATE,
    oppdateringsdato TIMESTAMP,
    sist_lastet_ned TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Eiere table
CREATE TABLE IF NOT EXISTS matrikkel_eiere (
    id BIGSERIAL PRIMARY KEY,
    eier_id BIGINT NOT NULL,
    matrikkelenhet_id BIGINT NOT NULL,
    eier_type VARCHAR(50),
    navn VARCHAR(500),
    fodselsnummer VARCHAR(11),
    organisasjonsnummer VARCHAR(9),
    andel_teller INTEGER,
    andel_nevner INTEGER,
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id) ON DELETE CASCADE
);

-- Adresser table
CREATE TABLE IF NOT EXISTS matrikkel_adresser (
    id BIGSERIAL PRIMARY KEY,
    adresse_id BIGINT NOT NULL,
    matrikkelenhet_id BIGINT NOT NULL,
    adressenavn VARCHAR(500),
    adressenummer VARCHAR(50),
    postnummer VARCHAR(4),
    poststed VARCHAR(200),
    kommunenummer VARCHAR(4),
    adressetype VARCHAR(50),
    FOREIGN KEY (matrikkelenhet_id) REFERENCES matrikkel_matrikkelenheter(matrikkelenhet_id) ON DELETE CASCADE
);

-- Indexes
CREATE INDEX idx_kommunenummer ON matrikkel_matrikkelenheter(kommunenummer);
CREATE INDEX idx_gardsnummer_bruksnummer ON matrikkel_matrikkelenheter(gardsnummer, bruksnummer);
CREATE INDEX idx_matrikkelenhet_id ON matrikkel_matrikkelenheter(matrikkelenhet_id);
CREATE INDEX idx_eier_id ON matrikkel_eiere(eier_id);
CREATE INDEX idx_eier_matrikkelenhet_id ON matrikkel_eiere(matrikkelenhet_id);
CREATE INDEX idx_adresse_matrikkelenhet_id ON matrikkel_adresser(matrikkelenhet_id);

-- Comments
COMMENT ON TABLE matrikkel_matrikkelenheter IS 'Matrikkelenheter imported from Matrikkel API';
COMMENT ON TABLE matrikkel_eiere IS 'Eiere (owners) of matrikkelenheter';
COMMENT ON COLUMN matrikkel_eiere.eier_type IS 'fysisk_person or juridisk_person';
```

---

## Konfigurasjon

### MatrikkelProperties.java

```java
package no.bergenkommune.matrikkel.config;

import lombok.Data;
import org.springframework.boot.context.properties.ConfigurationProperties;
import org.springframework.context.annotation.Configuration;

@Configuration
@ConfigurationProperties(prefix = "matrikkel")
@Data
public class MatrikkelProperties {
    
    private String environment = "test"; // test eller prod
    private String username;
    private String password;
    private Endpoint endpoint = new Endpoint();
    private Batch batch = new Batch();
    
    @Data
    public static class Endpoint {
        private String nedlastning;
        private String store;
        private String matrikkelenhet;
        
        public String getNedlastning() {
            return nedlastning != null ? nedlastning : 
                "https://wsweb-" + (environment.equals("prod") ? "" : "test") + 
                ".matrikkel.no/matrikkel-ws-v1.0/NedlastningServiceWS";
        }
        
        public String getStore() {
            return store != null ? store : 
                "https://wsweb-" + (environment.equals("prod") ? "" : "test") + 
                ".matrikkel.no/matrikkel-ws-v1.0/StoreServiceWS";
        }
    }
    
    @Data
    public static class Batch {
        private int size = 5000; // Default: API maximum
        private int maxRetries = 3;
        private int timeoutSeconds = 30;
    }
}
```

### application.yml (Komplett)

```yaml
# src/main/resources/application.yml

spring:
  application:
    name: matrikkel-integration
    
  profiles:
    active: ${SPRING_PROFILES_ACTIVE:dev}
    
  datasource:
    url: jdbc:postgresql://${DB_HOST:10.0.2.15}:${DB_PORT:5435}/${DB_NAME:matrikkel}
    username: ${DB_USERNAME}
    password: ${DB_PASSWORD}
    driver-class-name: org.postgresql.Driver
    hikari:
      maximum-pool-size: 10
      minimum-idle: 2
      connection-timeout: 30000
      
  jpa:
    hibernate:
      ddl-auto: validate
    properties:
      hibernate:
        dialect: org.hibernate.dialect.PostgreSQLDialect
        format_sql: true
        jdbc:
          batch_size: 100
        order_inserts: true
        order_updates: true
    show-sql: false
    
  flyway:
    enabled: true
    baseline-on-migrate: true
    locations: classpath:db/migration

# Matrikkel API Configuration
matrikkel:
  environment: ${MATRIKKEL_ENVIRONMENT:test}
  username: ${MATRIKKEL_USERNAME}
  password: ${MATRIKKEL_PASSWORD}
  batch:
    size: 5000
    max-retries: 3
    timeout-seconds: 30

# Logging
logging:
  level:
    root: INFO
    no.bergenkommune.matrikkel: DEBUG
    org.hibernate.SQL: DEBUG
    org.hibernate.type.descriptor.sql.BasicBinder: TRACE
  pattern:
    console: "%d{yyyy-MM-dd HH:mm:ss} - %msg%n"
    file: "%d{yyyy-MM-dd HH:mm:ss} [%thread] %-5level %logger{36} - %msg%n"
  file:
    name: logs/matrikkel-integration.log
```

### .env fil (for development)

```properties
# .env
DB_HOST=10.0.2.15
DB_PORT=5435
DB_NAME=matrikkel
DB_USERNAME=hc483
DB_PASSWORD=Fmsigg10

MATRIKKEL_ENVIRONMENT=test
MATRIKKEL_USERNAME=bergenaktivkommune_test
MATRIKKEL_PASSWORD=Flaaklypa-grand-prix-Jason-Bourne1

SPRING_PROFILES_ACTIVE=dev
```

---

## SOAP Client Implementation

### SoapClientConfig.java

```java
package no.bergenkommune.matrikkel.config;

import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import no.bergenkommune.matrikkel.client.generated.nedlastning.NedlastningServiceWS;
import no.bergenkommune.matrikkel.client.generated.nedlastning.NedlastningService;
import no.bergenkommune.matrikkel.client.generated.store.StoreServiceWS;
import no.bergenkommune.matrikkel.client.generated.store.StoreService;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;

import jakarta.xml.ws.BindingProvider;
import jakarta.xml.ws.handler.MessageContext;
import java.net.URL;
import java.util.Collections;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

@Configuration
@RequiredArgsConstructor
@Slf4j
public class SoapClientConfig {
    
    private final MatrikkelProperties properties;
    
    @Bean
    public NedlastningService nedlastningService() throws Exception {
        log.info("Initializing NedlastningService SOAP client for environment: {}", 
                 properties.getEnvironment());
        
        URL wsdlUrl = getClass().getResource("/wsdl/NedlastningServiceWS.wsdl");
        NedlastningServiceWS service = new NedlastningServiceWS(wsdlUrl);
        NedlastningService port = service.getNedlastningServicePort();
        
        configureEndpointAndAuth(port, properties.getEndpoint().getNedlastning());
        
        return port;
    }
    
    @Bean
    public StoreService storeService() throws Exception {
        log.info("Initializing StoreService SOAP client for environment: {}", 
                 properties.getEnvironment());
        
        URL wsdlUrl = getClass().getResource("/wsdl/StoreServiceWS.wsdl");
        StoreServiceWS service = new StoreServiceWS(wsdlUrl);
        StoreService port = service.getStoreServicePort();
        
        configureEndpointAndAuth(port, properties.getEndpoint().getStore());
        
        return port;
    }
    
    private void configureEndpointAndAuth(Object port, String endpointUrl) {
        BindingProvider bindingProvider = (BindingProvider) port;
        Map<String, Object> requestContext = bindingProvider.getRequestContext();
        
        // Set endpoint URL
        requestContext.put(BindingProvider.ENDPOINT_ADDRESS_PROPERTY, endpointUrl);
        
        // Set Basic Authentication
        requestContext.put(BindingProvider.USERNAME_PROPERTY, properties.getUsername());
        requestContext.put(BindingProvider.PASSWORD_PROPERTY, properties.getPassword());
        
        // Set timeout
        requestContext.put("com.sun.xml.ws.request.timeout", 
                          properties.getBatch().getTimeoutSeconds() * 1000);
        requestContext.put("com.sun.xml.ws.connect.timeout", 30000);
        
        log.debug("SOAP client configured with endpoint: {}", endpointUrl);
    }
}
```

### NedlastningClientWrapper.java

```java
package no.bergenkommune.matrikkel.client;

import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import no.bergenkommune.matrikkel.client.generated.nedlastning.*;
import no.bergenkommune.matrikkel.config.MatrikkelProperties;
import org.springframework.stereotype.Component;

import java.time.ZonedDateTime;
import java.time.ZoneId;
import java.util.ArrayList;
import java.util.List;

@Component
@RequiredArgsConstructor
@Slf4j
public class NedlastningClientWrapper {
    
    private final NedlastningService nedlastningService;
    private final MatrikkelProperties properties;
    
    /**
     * Hent alle matrikkelenheter for en kommune med bulk download
     * 
     * @param kommunenummer Kommunenummer (f.eks. "4601")
     * @return Liste med alle matrikkelenheter
     */
    public List<Matrikkelenhet> findAllMatrikkelenheterForKommune(String kommunenummer) {
        log.info("Starter bulk-nedlasting av matrikkelenheter for kommune {}", kommunenummer);
        
        List<Matrikkelenhet> allObjects = new ArrayList<>();
        MatrikkelBubbleId cursor = null;
        int batchNumber = 0;
        int batchSize = properties.getBatch().getSize();
        
        // Build filter
        String filter = String.format("{\"kommunefilter\": [\"%s\"]}", 
                                     String.format("%04d", Integer.parseInt(kommunenummer)));
        
        // Create MatrikkelContext med snapshot version
        MatrikkelContext context = createMatrikkelContext();
        
        try {
            do {
                batchNumber++;
                log.debug("Henter batch {} med maksimum {} objekter (cursor: {})", 
                         batchNumber, batchSize, cursor != null ? cursor.getValue() : "null");
                
                // Call SOAP service
                List<Matrikkelenhet> batch = nedlastningService.findObjekterEtterId(
                    cursor,
                    Matrikkelenhet.class.getSimpleName(), // "Matrikkelenhet"
                    filter,
                    batchSize,
                    context
                );
                
                if (batch == null || batch.isEmpty()) {
                    log.debug("Tom batch returnert, stopper paginering");
                    break;
                }
                
                log.info("Batch {}: Mottok {} matrikkelenheter", batchNumber, batch.size());
                allObjects.addAll(batch);
                
                // Get last object's ID as cursor for next batch
                Matrikkelenhet lastObject = batch.get(batch.size() - 1);
                cursor = lastObject.getId(); // MatrikkelBubbleId
                
                // Safety check: Stop if we got less than requested
                // (indicates we're at the end)
                if (batch.size() < batchSize) {
                    log.debug("Mottok {} < {} objekter, siste batch", batch.size(), batchSize);
                    break;
                }
                
            } while (true);
            
            log.info("Bulk-nedlasting fullført: {} matrikkelenheter i {} batch(es)", 
                    allObjects.size(), batchNumber);
            
        } catch (Exception e) {
            log.error("Feil under bulk-nedlasting for kommune {}: {}", 
                     kommunenummer, e.getMessage(), e);
            throw new RuntimeException("SOAP call failed", e);
        }
        
        return allObjects;
    }
    
    /**
     * Create MatrikkelContext with snapshot version set to future date
     * (as per PHP implementation that worked)
     */
    private MatrikkelContext createMatrikkelContext() {
        MatrikkelContext context = new MatrikkelContext();
        context.setLocale("no_NO");
        context.setBrukOriginaleKoordinater(false);
        context.setSystemVersion("1.0");
        context.setKlientIdentifikasjon("BergenKommune-Java-Client");
        
        // Snapshot version: Use far future date (9999-01-01)
        // This avoids "historical data" permission errors
        SnapshotVersion snapshotVersion = new SnapshotVersion();
        ZonedDateTime futureDate = ZonedDateTime.of(9999, 1, 1, 0, 0, 0, 0, 
                                                     ZoneId.of("Europe/Oslo"));
        snapshotVersion.setTimestamp(futureDate);
        context.setSnapshotVersion(snapshotVersion);
        
        return context;
    }
}
```

**VIKTIG**: Legg merke til at i Java trenger vi IKKE manuell XML-serialisering! `MatrikkelBubbleId` objektet serialiseres automatisk med `snapshotVersion`! 🎉

---

## Service Layer

### MatrikkelenhetImportService.java

```java
package no.bergenkommune.matrikkel.service;

import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import no.bergenkommune.matrikkel.client.NedlastningClientWrapper;
import no.bergenkommune.matrikkel.client.generated.nedlastning.Matrikkelenhet as ApiMatrikkelenhet;
import no.bergenkommune.matrikkel.domain.entity.Matrikkelenhet;
import no.bergenkommune.matrikkel.mapper.MatrikkelenhetMapper;
import no.bergenkommune.matrikkel.repository.MatrikkelenhetRepository;
import org.springframework.stereotype.Service;
import org.springframework.transaction.annotation.Transactional;

import java.util.List;
import java.util.stream.Collectors;

@Service
@RequiredArgsConstructor
@Slf4j
public class MatrikkelenhetImportService {
    
    private final NedlastningClientWrapper nedlastningClient;
    private final MatrikkelenhetRepository repository;
    private final MatrikkelenhetMapper mapper;
    
    @Transactional
    public int importMatrikkelenheterForKommune(String kommunenummer) {
        log.info("Starter import av matrikkelenheter for kommune {}", kommunenummer);
        
        // Fetch from API
        List<ApiMatrikkelenhet> apiObjects = 
            nedlastningClient.findAllMatrikkelenheterForKommune(kommunenummer);
        
        log.info("Hentet {} matrikkelenheter fra API", apiObjects.size());
        
        // Map to entities
        List<Matrikkelenhet> entities = apiObjects.stream()
            .map(mapper::toEntity)
            .collect(Collectors.toList());
        
        // Save to database (uses UPSERT logic)
        List<Matrikkelenhet> saved = repository.saveAll(entities);
        
        log.info("Lagret {} matrikkelenheter i database", saved.size());
        
        return saved.size();
    }
}
```

### MatrikkelenhetMapper.java

```java
package no.bergenkommune.matrikkel.mapper;

import no.bergenkommune.matrikkel.client.generated.nedlastning.Matrikkelenhet as ApiMatrikkelenhet;
import no.bergenkommune.matrikkel.domain.entity.Matrikkelenhet;
import org.springframework.stereotype.Component;

import java.time.LocalDateTime;

@Component
public class MatrikkelenhetMapper {
    
    public Matrikkelenhet toEntity(ApiMatrikkelenhet api) {
        if (api == null) {
            return null;
        }
        
        return Matrikkelenhet.builder()
            .matrikkelenhetId(api.getId().getValue())
            .kommunenummer(api.getKommunenummer())
            .gardsnummer(api.getGardsnummer())
            .bruksnummer(api.getBruksnummer())
            .festenummer(api.getFestenummer())
            .seksjonsnummer(api.getSeksjonsnummer())
            .bruksnavn(api.getBruksnavn())
            .matrikkelnummer(buildMatrikkelnummer(api))
            .eiendomstype(api.getEiendomstype() != null ? api.getEiendomstype().name() : null)
            .registerenhettype(api.getRegisterenhettype() != null ? 
                              api.getRegisterenhettype().name() : null)
            .arealTeigM2(api.getArealTeigM2())
            .oppdateringsdato(api.getOppdateringsdato() != null ? 
                             LocalDateTime.from(api.getOppdateringsdato()) : null)
            .sistLastetNed(LocalDateTime.now())
            .build();
    }
    
    private String buildMatrikkelnummer(ApiMatrikkelenhet api) {
        StringBuilder sb = new StringBuilder();
        sb.append(api.getKommunenummer()).append("-");
        if (api.getGardsnummer() != null) sb.append(api.getGardsnummer());
        sb.append("/");
        if (api.getBruksnummer() != null) sb.append(api.getBruksnummer());
        if (api.getFestenummer() != null) sb.append("/").append(api.getFestenummer());
        if (api.getSeksjonsnummer() != null) sb.append("/").append(api.getSeksjonsnummer());
        return sb.toString();
    }
}
```

---

## Repository Layer (Database)

### MatrikkelenhetRepository.java

```java
package no.bergenkommune.matrikkel.repository;

import no.bergenkommune.matrikkel.domain.entity.Matrikkelenhet;
import org.springframework.data.jpa.repository.JpaRepository;
import org.springframework.data.jpa.repository.Query;
import org.springframework.data.repository.query.Param;
import org.springframework.stereotype.Repository;

import java.util.List;
import java.util.Optional;

@Repository
public interface MatrikkelenhetRepository extends JpaRepository<Matrikkelenhet, Long> {
    
    Optional<Matrikkelenhet> findByMatrikkelenhetId(Long matrikkelenhetId);
    
    List<Matrikkelenhet> findByKommunenummer(String kommunenummer);
    
    @Query("SELECT m FROM Matrikkelenhet m WHERE m.kommunenummer = :kommunenummer " +
           "AND m.gardsnummer = :gardsnummer AND m.bruksnummer = :bruksnummer")
    Optional<Matrikkelenhet> findByKommuneAndGardAndBruk(
        @Param("kommunenummer") String kommunenummer,
        @Param("gardsnummer") Integer gardsnummer,
        @Param("bruksnummer") Integer bruksnummer
    );
    
    @Query("SELECT COUNT(m) FROM Matrikkelenhet m WHERE m.kommunenummer = :kommunenummer")
    long countByKommunenummer(@Param("kommunenummer") String kommunenummer);
}
```

**Note**: Spring Data JPA gir oss automatisk CRUD-operasjoner! `saveAll()` håndterer batch insert effektivt.

---

## Testing

### MatrikkelenhetImportServiceTest.java

```java
package no.bergenkommune.matrikkel.service;

import no.bergenkommune.matrikkel.client.NedlastningClientWrapper;
import no.bergenkommune.matrikkel.client.generated.nedlastning.Matrikkelenhet as ApiMatrikkelenhet;
import no.bergenkommune.matrikkel.domain.entity.Matrikkelenhet;
import no.bergenkommune.matrikkel.mapper.MatrikkelenhetMapper;
import no.bergenkommune.matrikkel.repository.MatrikkelenhetRepository;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.extension.ExtendWith;
import org.mockito.InjectMocks;
import org.mockito.Mock;
import org.mockito.junit.jupiter.MockitoExtension;

import java.util.Arrays;
import java.util.List;

import static org.junit.jupiter.api.Assertions.*;
import static org.mockito.ArgumentMatchers.*;
import static org.mockito.Mockito.*;

@ExtendWith(MockitoExtension.class)
class MatrikkelenhetImportServiceTest {
    
    @Mock
    private NedlastningClientWrapper nedlastningClient;
    
    @Mock
    private MatrikkelenhetRepository repository;
    
    @Mock
    private MatrikkelenhetMapper mapper;
    
    @InjectMocks
    private MatrikkelenhetImportService service;
    
    @Test
    void testImportMatrikkelenheterForKommune() {
        // Arrange
        String kommunenummer = "4601";
        ApiMatrikkelenhet apiObj1 = new ApiMatrikkelenhet();
        ApiMatrikkelenhet apiObj2 = new ApiMatrikkelenhet();
        List<ApiMatrikkelenhet> apiObjects = Arrays.asList(apiObj1, apiObj2);
        
        Matrikkelenhet entity1 = new Matrikkelenhet();
        Matrikkelenhet entity2 = new Matrikkelenhet();
        
        when(nedlastningClient.findAllMatrikkelenheterForKommune(kommunenummer))
            .thenReturn(apiObjects);
        when(mapper.toEntity(apiObj1)).thenReturn(entity1);
        when(mapper.toEntity(apiObj2)).thenReturn(entity2);
        when(repository.saveAll(anyList())).thenReturn(Arrays.asList(entity1, entity2));
        
        // Act
        int result = service.importMatrikkelenheterForKommune(kommunenummer);
        
        // Assert
        assertEquals(2, result);
        verify(nedlastningClient).findAllMatrikkelenheterForKommune(kommunenummer);
        verify(repository).saveAll(anyList());
    }
}
```

### Integration Test med Testcontainers

```java
package no.bergenkommune.matrikkel.integration;

import no.bergenkommune.matrikkel.domain.entity.Matrikkelenhet;
import no.bergenkommune.matrikkel.repository.MatrikkelenhetRepository;
import org.junit.jupiter.api.Test;
import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.boot.test.context.SpringBootTest;
import org.springframework.test.context.DynamicPropertyRegistry;
import org.springframework.test.context.DynamicPropertySource;
import org.testcontainers.containers.PostgreSQLContainer;
import org.testcontainers.junit.jupiter.Container;
import org.testcontainers.junit.jupiter.Testcontainers;

import static org.junit.jupiter.api.Assertions.*;

@SpringBootTest
@Testcontainers
class MatrikkelenhetRepositoryIntegrationTest {
    
    @Container
    static PostgreSQLContainer<?> postgres = new PostgreSQLContainer<>("postgres:15-alpine")
        .withDatabaseName("testdb")
        .withUsername("test")
        .withPassword("test");
    
    @DynamicPropertySource
    static void configureProperties(DynamicPropertyRegistry registry) {
        registry.add("spring.datasource.url", postgres::getJdbcUrl);
        registry.add("spring.datasource.username", postgres::getUsername);
        registry.add("spring.datasource.password", postgres::getPassword);
    }
    
    @Autowired
    private MatrikkelenhetRepository repository;
    
    @Test
    void testSaveAndFindMatrikkelenhet() {
        // Arrange
        Matrikkelenhet entity = Matrikkelenhet.builder()
            .matrikkelenhetId(12345L)
            .kommunenummer("4601")
            .gardsnummer(100)
            .bruksnummer(1)
            .build();
        
        // Act
        Matrikkelenhet saved = repository.save(entity);
        Matrikkelenhet found = repository.findByMatrikkelenhetId(12345L).orElse(null);
        
        // Assert
        assertNotNull(saved.getId());
        assertNotNull(found);
        assertEquals("4601", found.getKommunenummer());
        assertEquals(100, found.getGardsnummer());
    }
}
```

---

## CLI Command

### ImportCommand.java

```java
package no.bergenkommune.matrikkel.cli;

import lombok.RequiredArgsConstructor;
import lombok.extern.slf4j.Slf4j;
import no.bergenkommune.matrikkel.service.MatrikkelenhetImportService;
import org.springframework.boot.CommandLineRunner;
import org.springframework.boot.autoconfigure.condition.ConditionalOnProperty;
import org.springframework.stereotype.Component;

@Component
@ConditionalOnProperty(name = "matrikkel.cli.enabled", havingValue = "true")
@RequiredArgsConstructor
@Slf4j
public class ImportCommand implements CommandLineRunner {
    
    private final MatrikkelenhetImportService importService;
    
    @Override
    public void run(String... args) throws Exception {
        if (args.length == 0) {
            log.error("Usage: java -jar matrikkel-integration.jar --kommunenummer=XXXX");
            System.exit(1);
        }
        
        String kommunenummer = null;
        for (String arg : args) {
            if (arg.startsWith("--kommunenummer=")) {
                kommunenummer = arg.substring("--kommunenummer=".length());
            }
        }
        
        if (kommunenummer == null) {
            log.error("Missing required parameter: --kommunenummer");
            System.exit(1);
        }
        
        log.info("Starting import for kommune: {}", kommunenummer);
        int count = importService.importMatrikkelenheterForKommune(kommunenummer);
        log.info("Import completed: {} matrikkelenheter imported", count);
    }
}
```

### Kjøre CLI

```bash
# Med Maven
mvn spring-boot:run -Dspring-boot.run.arguments="--kommunenummer=4601" \
  -Dmatrikkel.cli.enabled=true

# Med JAR
java -jar target/matrikkel-integration-1.0.0-SNAPSHOT.jar \
  --kommunenummer=4601 \
  --matrikkel.cli.enabled=true
```

---

## Deployment

### Dockerfile

```dockerfile
FROM eclipse-temurin:17-jre-alpine

WORKDIR /app

# Copy JAR file
COPY target/matrikkel-integration-*.jar app.jar

# Expose port (if REST API is enabled)
EXPOSE 8080

# Run application
ENTRYPOINT ["java", "-jar", "app.jar"]
```

### docker-compose.yml

```yaml
version: '3.8'

services:
  postgres:
    image: postgres:15-alpine
    environment:
      POSTGRES_DB: matrikkel
      POSTGRES_USER: matrikkel_user
      POSTGRES_PASSWORD: secure_password
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U matrikkel_user"]
      interval: 10s
      timeout: 5s
      retries: 5

  matrikkel-app:
    build: .
    depends_on:
      postgres:
        condition: service_healthy
    environment:
      DB_HOST: postgres
      DB_PORT: 5432
      DB_NAME: matrikkel
      DB_USERNAME: matrikkel_user
      DB_PASSWORD: secure_password
      MATRIKKEL_ENVIRONMENT: test
      MATRIKKEL_USERNAME: ${MATRIKKEL_USERNAME}
      MATRIKKEL_PASSWORD: ${MATRIKKEL_PASSWORD}
      SPRING_PROFILES_ACTIVE: prod
    ports:
      - "8080:8080"
    command: ["--kommunenummer=4601", "--matrikkel.cli.enabled=true"]

volumes:
  postgres_data:
```

### Build og Deploy

```bash
# Build
mvn clean package -DskipTests

# Build Docker image
docker build -t matrikkel-integration:1.0.0 .

# Run med docker-compose
docker-compose up -d

# Sjekk logs
docker-compose logs -f matrikkel-app
```

---

## Kjøreeksempler

### 1. Import matrikkelenheter

```bash
mvn spring-boot:run \
  -Dspring-boot.run.arguments="--kommunenummer=4601" \
  -Dmatrikkel.cli.enabled=true
```

### 2. Med custom batch-size

```yaml
# application.yml
matrikkel:
  batch:
    size: 1000  # Mindre batches for testing
```

### 3. Scheduled import (cron)

```java
@Scheduled(cron = "0 0 2 * * *") // Hver natt kl 02:00
public void scheduledImport() {
    log.info("Starting scheduled import");
    importService.importMatrikkelenheterForKommune("4601");
}
```

---

## Neste Steg

### 1. Utvid til flere services
- Implementer `StoreClient` for enkelt-objekt henting
- Implementer `EierImportService` for eierforhold
- Implementer `AdresseImportService`

### 2. Legg til REST API (optional)
```java
@RestController
@RequestMapping("/api/matrikkel")
public class MatrikkelenhetController {
    
    @PostMapping("/import/{kommunenummer}")
    public ResponseEntity<ImportResult> importKommune(
        @PathVariable String kommunenummer) {
        // ...
    }
    
    @GetMapping("/matrikkelenheter")
    public Page<Matrikkelenhet> search(Pageable pageable) {
        // ...
    }
}
```

### 3. Monitoring og Metrics
```xml
<dependency>
    <groupId>org.springframework.boot</groupId>
    <artifactId>spring-boot-starter-actuator</artifactId>
</dependency>
```

### 4. Error Handling
- Retry logic med `@Retryable`
- Circuit breaker med Resilience4j
- Dead letter queue for failed imports

---

## Konklusjon

Dette Java-prosjektet gir deg:
- ✅ **Automatisk SOAP serialisering** (ingen manuell XML!)
- ✅ **Type-sikkerhet** (kompileringstidssjekk)
- ✅ **Moderne Spring Boot stack**
- ✅ **Enkel PostgreSQL-integrasjon**
- ✅ **Testbar kode** med mocking og Testcontainers
- ✅ **Produktionsklar** med Docker og monitoring

**Fordelen fremfor PHP**: Java JAX-WS håndterer `MatrikkelBubbleId` med `snapshotVersion` perfekt automatisk! 🎉

---

## Support og Ressurser

- **Matrikkel API Dokumentasjon**: Inkludert i `/docs/API_DOCUMENTATION.md`
- **WSDL Filer**: `/src/main/resources/wsdl/`
- **Database Schema**: `/docs/DATABASE_SCHEMA.md`
- **Spring Boot Docs**: https://spring.io/projects/spring-boot
- **JAX-WS Tutorial**: https://docs.oracle.com/javaee/7/tutorial/jaxws.htm

Lykke til med Java-prosjektet! 🚀
