/** Mirrors the PHP NameSuffix enum (values and labels). Kept equal by tests/Feature/Models/StatusListsTest.php. */
export const nameSuffixes = [
    { value: 'jr', label: 'Jr.' },
    { value: 'sr', label: 'Sr.' },
    { value: 'ii', label: 'II' },
    { value: 'iii', label: 'III' },
    { value: 'iv', label: 'IV' },
    { value: 'v', label: 'V' },
] as const;

export type NameSuffix = (typeof nameSuffixes)[number]['value'];

/** The fields of a person's name in every form. Middle name and suffix are optional. */
export interface PersonName {
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: NameSuffix | null;
}
