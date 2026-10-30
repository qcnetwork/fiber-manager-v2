export type AssetStatus = 'aktif' | 'rencana' | 'rusak';
export type PortStatus = 'kosong' | 'terpakai' | 'booking' | 'rusak';

export interface Area {
    id: number;
    code: string;
    name: string;
    center_lat: number | null;
    center_lng: number | null;
}

export interface OdcMarker {
    id: number;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    status: AssetStatus;
    capacity: number;
    odps_count: number;
}

export interface OdpMarker {
    id: number;
    odc_id: number | null;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    status: AssetStatus;
    capacity: number;
    used_ports: number;
    free_ports: number;
    odc_latlng: [number, number] | null;
}

interface AssetFields {
    id: number;
    area_id: number;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    address: string | null;
    capacity: number;
    splitter_ratio: string | null;
    status: AssetStatus;
    installed_at: string | null;
    notes: string | null;
    area?: Pick<Area, 'id' | 'code' | 'name'>;
    photos?: Photo[];
}

export interface Photo {
    id: number;
    url: string;
    thumb_url: string;
    caption: string | null;
    width: number;
    height: number;
    size: number;
    user_id: number | null;
    user?: { id: number; name: string } | null;
    created_at: string;
}

export type Odc = AssetFields;

export interface Odp extends AssetFields {
    odc_id: number | null;
    odc_port: number | null;
    pole_number: string | null;
    odc?: { id: number; code: string; name: string | null } | null;
    ports?: OdpPort[];
}

export interface OdcDetail {
    odc: Odc;
    odps: { id: number; code: string; odc_port: number | null; capacity: number; status: AssetStatus; used_ports: number }[];
    used_odc_ports: number[];
    customer_count: number;
}

export interface Customer {
    id: number;
    customer_code: string | null;
    name: string;
    phone: string | null;
    address: string | null;
    lat: number | null;
    lng: number | null;
    package: string | null;
    ont_sn: string | null;
    status: string;
    area_id?: number;
    area?: Pick<Area, 'id' | 'code' | 'name'>;
    photos?: Photo[];
    /** The port the customer is connected to, with its ODP (customer detail only). */
    port?: {
        id: number;
        odp_id: number;
        number: number;
        odp: { id: number; code: string; name: string | null; lat: number; lng: number } | null;
    } | null;
}

export interface CustomerMarker {
    id: number;
    name: string;
    customer_code: string | null;
    lat: number;
    lng: number;
    odp_latlng: [number, number] | null;
}

export interface OdpPort {
    id: number;
    odp_id: number;
    number: number;
    status: PortStatus;
    notes: string | null;
    customer: Customer | null;
}

export interface OdcOption {
    id: number;
    code: string;
    name: string | null;
    capacity: number;
    used_ports: number[];
    distance: number | null;
}

export interface NearestOdp {
    id: number;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    capacity: number;
    free_ports: number;
    used_ports: number;
    distance: number;
    address: string | null;
}

export type AssetKind = 'odc' | 'odp';
export type MapItemKind = AssetKind | 'cable' | 'customer';
/** What a photo can belong to. */
export type PhotoOwner = AssetKind | 'customer';

export type CableType = 'feeder' | 'distribusi' | 'drop';
export type LatLng = [number, number];

export interface CableLine {
    id: number;
    code: string | null;
    type: CableType;
    cores: number;
    status: AssetStatus;
    path: LatLng[];
    length_m: number;
}

interface CableEnd {
    id: number;
    code: string;
    lat: number;
    lng: number;
}

export interface Cable extends CableLine {
    area_id: number;
    from_type: AssetKind | null;
    from_id: number | null;
    to_type: AssetKind | null;
    to_id: number | null;
    notes: string | null;
    area?: Pick<Area, 'id' | 'code' | 'name'>;
    from?: CableEnd | null;
    to?: CableEnd | null;
}

/**
 * A cable end attached to an ODC/ODP while drawing.
 */
export interface CableEndpoint {
    type: AssetKind;
    id: number;
    code: string;
}
