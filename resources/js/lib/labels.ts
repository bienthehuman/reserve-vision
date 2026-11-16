/** The words for the property enums, as the public pages show them. */
export const genderLabel = { any: 'Anyone', male_only: 'Men only', female_only: 'Women only' } as const;

export const kindLabel = { apartment: 'Apartment', boarding_house: 'Boarding house' } as const;

export type GenderPolicy = keyof typeof genderLabel;
export type PropertyKind = keyof typeof kindLabel;
