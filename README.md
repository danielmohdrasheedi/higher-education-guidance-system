# Higher Education Guidance System

A web system that helps Malaysian students find the public university programmes they can apply for. Students enter their results once, and the system checks them against the entry requirements of every programme listed in e-Panduan UPUOnline.

Developed for CSC2854 Project, Diploma in Computer Science, Kolej Profesional MARA Beranang.

## Features

- **Academic profile input:** results for 26 qualification types, including SPM, STPM, STAM, matriculation, foundation and diploma, plus age, Bumiputera status and colour blindness, and optional details some courses ask for: sports, IELTS or TOEFL iBT, music grade and Quran memorisation.
- **Automated eligibility checking:** every offered programme is marked "You qualify", "You don't qualify" or "Check the rest yourself".
- **Ineligibility diagnostics:** each unmet requirement is explained in plain language.
- **Multi-criteria filtering:** course name or code search, institution, location, competition and interview or test filters work together. Location is a state, or, once an institution is chosen, a state or one of its campuses. The institution and location lists only offer choices that have courses. Each course shows the campuses it is offered at.
- **Programme comparison:** save favourites and compare 2 to 4 programmes side by side.
- **Admission trends:** applications, offers, offer rate, average merit score and competition level from UPU Online figures.
- **Official links:** every programme links to its e-Panduan page.

Results and favourites are kept in the session only and are never saved to the database.

## Requirements

- PHP 8.5 with the MongoDB extension
- Composer
- MongoDB 9

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Set `MONGODB_URI` and `MONGODB_DATABASE` in `.env`, then load the data and start the server:

```bash
php artisan migrate
php artisan programmes:import
php artisan trends:import
php artisan serve
```

The imports read the collected e-Panduan and UPU Online data in `database/data`.

## Tests

```bash
php artisan test
```

## Author

Daniel bin Mohd Rasheedi (BCS2403-009)
