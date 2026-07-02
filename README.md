# Campus Relay - XMUM Peer-to-Peer Rental Marketplace

Campus Relay is a secure, high-end peer-to-peer (P2P) utility designed specifically for students at **Xiamen University Malaysia (XMUM)**. The platform enables students to list their personal transport assets (Scooters, Bicycles, Skateboards, Motorcycles, Cars, and Accessories) for rent, establishing an efficient, localized campus sharing economy.

---

## 🌟 Core UX & Interactive Features

- **Immersive XMUM Branding**: A modern glassmorphic dashboard overlaying a high-resolution backdrop of Xiamen University Malaysia's iconic campus.
- **Dedicated Checkout Portal (`rent_item.php`)**: A clean booking screen featuring real-time duration and pricing calculators, wallet balance checks, and secure date pickers.
- **Interactive Campus Maps**:
  - *Browse Map*: Powered by Leaflet.js, displaying active vehicle pins on a campus map.
  - *Add Listing Pin*: Lenders drop a pin on a campus map to log coordinates.
- **Real-Time Peer Messaging**: Direct chat widgets inside rental panels utilizing a fast, lightweight AJAX polling engine.
- **Booking Countdown Timers**: Active rentals display remaining hours, minutes, and seconds before due dates.
- **Rating Feedback Loop**: 5-star ratings and textual reviews submitted upon successful check-in.

---

## 🔒 Security & Escrow Architecture

- **Escrow Wallet System**: Booking requests automatically verify the renter's wallet balance and hold a **RM 20.00 security deposit** in escrow.
- **Lock Code Release**: Lenders provide combination padlock details when listing. The code is concealed in the database and released to the renter only when booking status changes to `'active'` (approved).
- **Inspection Checklists**: Renters submit condition notes and parked vehicle photos upon check-in.
- **Escrow Refund or Dispute**:
  - *Clean Return*: Lender check-in completes the transaction and automatically refunds the RM 20.00 deposit back to the renter.
  - *Dispute Flagging*: Lender reports damage, freezing the RM 20.00 in escrow for administrative review.

---

## 🛠️ Technology Stack

- **Backend**: PHP (using secure PDO database connectors and session-based state authentication)
- **Database**: MariaDB / MySQL
- **Frontend**: HTML5, CSS3 (Vanilla Glassmorphism UI), Vanilla JavaScript
- **API integrations**: Leaflet.js OpenStreetMap API

---

## 🚀 Setup & Installation

### 1. Import Database Schema
Import the SQL migrations inside the XAMPP MySQL CLI or phpMyAdmin:
1. Initialize core tables: `scratch/migrate.sql`
2. Apply P2P columns: `scratch/migrate_p2p.sql`
3. Split building spot columns: `scratch/migrate_locations.sql`
4. Apply escrow, chat, and review tables: `scratch/migrate_security.sql`

```bash
# Import schema using MySQL CLI
C:\xampp\mysql\bin\mysql.exe -u root < scratch/migrate.sql
C:\xampp\mysql\bin\mysql.exe -u root < scratch/migrate_p2p.sql
C:\xampp\mysql\bin\mysql.exe -u root < scratch/migrate_locations.sql
C:\xampp\mysql\bin\mysql.exe -u root < scratch/migrate_security.sql
```

### 2. Configure Local Web Server
1. Clone the project files into your local Apache root: `c:\xampp\htdocs\campusrelay-app`
2. Start the Apache and MySQL modules inside XAMPP Control Panel.
3. Open your browser and navigate to: `http://localhost/campusrelay-app`

---

## 📝 Authorship & Citations

- **Developed by**: Yunus Said (Xiamen University Malaysia)
- **Publication Date**: June 2026
- **Last Edited**: July 2, 2026
- **Citations**: Images and vector campus backdrops are sourced from open-license academic repositories.
