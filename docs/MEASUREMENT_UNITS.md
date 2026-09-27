# Units and quantities

Products are not limited to pieces. The protected measurement subsystem supports count, mass, volume, length, area and time units, with translations and optional UNECE/Google mappings.

Seeded EU/UA-oriented units include item/count, set, pair, pack, sheet, roll, kg/g/mg/t, l/ml/cl/m³, m/cm/mm, m² and hour. New units can be added without schema changes.

Every variant has a sale unit, minimum quantity, maximum quantity and fractional step. Inventory already uses DECIMAL(18,6), so products may safely be sold as 2.5 m, 0.75 kg or 1.25 l. Unit-pricing measure/base measure fields are separate from inventory quantity and are projected to Google Merchant data when applicable.
