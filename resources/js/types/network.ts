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
    /** A technician's draft not applied yet (id is the draft's token). */
    draft?: boolean;
    id: number;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    status: AssetStatus;
    capacity: number;
    odps_count: number;
    /** Location of the POP whose OLT feeds this ODC, for the guide line. */
    pop_latlng: [number, number] | null;
}

/** A POP is an area with a location; its OLTs sit there. */
export interface PopMarker {
    id: number;
    code: string;
    name: string;
    lat: number;
    lng: number;
    olts_count: number;
    ports_total: number;
    ports_used: number;
}

export type OltPortStatus = 'normal' | 'rusak';

export interface OltPort {
    id: number;
    olt_id: number;
    slot: number;
    port: number;
    status: OltPortStatus;
    notes: string | null;
    odcs: { id: number; code: string; name: string | null; status: AssetStatus }[];
}

export interface Olt {
    id: number;
    area_id: number;
    code: string;
    name: string;
    brand: string | null;
    ip_address: string | null;
    notes: string | null;
    pon_ports: number;
    ports: OltPort[];
}

export interface Pop {
    id: number;
    code: string;
    name: string;
    lat: number | null;
    lng: number | null;
    address: string | null;
    notes: string | null;
    olts: Olt[];
    photos?: Photo[];
}

export interface OdpMarker {
    /** A technician's draft not applied yet (id is the draft's token). */
    draft?: boolean;
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

export interface Odc extends AssetFields {
    olt_port_id: number | null;
    olt_port?: {
        id: number;
        olt_id: number;
        slot: number;
        port: number;
        status: OltPortStatus;
        olt: { id: number; area_id: number; code: string; name: string } | null;
    } | null;
}

export interface Odp extends AssetFields {
    odc_id: number | null;
    odc_port: number | null;
    pole_number: string | null;
    odc?: { id: number; code: string; name: string | null } | null;
    ports?: OdpPort[];
    /** Cores ending on the splitter inputs / output ports (detail only). */
    core_terminations?: PanelTermination[];
}

export type SpliceBoxType = 'joint_box' | 'roset';

/** A joint box or rosette on the map. */
export interface JointMarker {
    /** A technician's draft not applied yet (id is the draft's token). */
    draft?: boolean;
    id: number;
    type: SpliceBoxType;
    code: string;
    name: string | null;
    lat: number;
    lng: number;
    status: AssetStatus;
}

/** A joint box or rosette: where cables are spliced together. */
export interface SpliceBox extends Omit<AssetFields, 'capacity' | 'splitter_ratio'> {
    type: SpliceBoxType;
    pole_number: string | null;
    /** How many splices the trays hold; optional. */
    capacity: number | null;
    splitter_ratio?: null;
}

export interface JointDetail {
    joint: SpliceBox;
    cables: { id: number; code: string | null; type: CableType; cores: number; status: AssetStatus; length_m: number }[];
}

export interface OdcDetail {
    odc: Odc;
    odps: { id: number; code: string; odc_port: number | null; capacity: number; status: AssetStatus; used_ports: number }[];
    used_odc_ports: number[];
    customer_count: number;
    /** Cores ending on the ODC's splitter inputs / output ports. */
    core_terminations?: PanelTermination[];
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
    area_id: number;
    area: { id: number; code: string; name: string } | null;
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

export type AssetKind = 'odc' | 'odp' | 'joint';
/** What a cable end can attach to. */
export type CableEndKind = 'pop' | AssetKind;
export type MapItemKind = CableEndKind | 'cable' | 'customer';
/** What a photo can belong to. */
export type PhotoOwner = CableEndKind | 'customer';

export type CableType = 'feeder' | 'distribusi' | 'drop';
export type LatLng = [number, number];

export interface CableLine {
    /** A technician's draft not applied yet (id is the draft's token). */
    draft?: boolean;
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
    from_type: CableEndKind | null;
    from_id: number | null;
    to_type: CableEndKind | null;
    to_id: number | null;
    notes: string | null;
    area?: Pick<Area, 'id' | 'code' | 'name'>;
    from?: CableEnd | null;
    to?: CableEnd | null;
    tube_size: number;
    /** Start, pass-through points and end, in order along the cable (only from the detail endpoint). */
    route?: CableRoutePoint[];
    /** One row per core (only from the detail endpoint). */
    core_list?: CableCore[];
}

export type CoreStatus = 'kosong' | 'terpakai' | 'rusak';
/** Which way along its cable a core is taken from a location: towards the cable's start or its end. */
export type CoreSide = 'from' | 'to';

export interface CableCore {
    id: number;
    number: number;
    status: CoreStatus;
    notes: string | null;
    /** How many connections (splices and terminations) the core has. */
    splices?: number;
}

/** Cables at a splice location with their cores, and the splices made there. */
export interface SpliceData {
    /** Only at an ODP/ODC: its splitter and output ports, for ending cores on an input or output port. */
    terminal: {
        kind: 'odp' | 'odc';
        capacity: number;
        splitter_ratio: string | null;
        max_inputs: number;
        /** `feeds`: the customer on an ODP port, the ODP on an ODC port. */
        ports: { number: number; status: string; feeds: string | null }[];
    } | null;
    terminations: {
        id: number;
        kind: TerminationKind;
        port: number;
        notes: string | null;
        core: { core_id: number; cable_id: number; number: number; side: CoreSide };
    }[];
    cables: {
        id: number;
        code: string | null;
        type: CableType;
        cores: number;
        tube_size: number;
        /** One way at a cable end, two where the cable passes through; `toward` is the next point that way. */
        sides: { side: CoreSide; toward: string | null }[];
        /** `used_sides`: the ways the core is already taken here. */
        cores_list: (CableCore & { splices: number; used_sides: CoreSide[] })[];
    }[];
    splices: {
        id: number;
        notes: string | null;
        a: { core_id: number; cable_id: number; number: number; side: CoreSide };
        b: { core_id: number; cable_id: number; number: number; side: CoreSide };
    }[];
}

interface TraceEnd {
    kind: CableEndKind;
    id: number;
    code: string;
}

/** A fiber path: cores in order, joined by splices (`joins[i]` sits between `segments[i]` and `segments[i + 1]`). */
export interface CoreTrace {
    segments: {
        core_id: number;
        number: number;
        status: CoreStatus;
        notes: string | null;
        cable: { id: number; code: string | null; type: CableType; cores: number; tube_size: number; from: TraceEnd | null; to: TraceEnd | null };
        /** Where this piece of the core begins and ends, in path order (null: an unattached cable end). */
        span: { from: TraceEnd | null; to: TraceEnd | null };
        piece: string;
    }[];
    joins: { splice_id: number; kind: CableEndKind; id: number; code: string | null; notes: string | null }[];
    /** Where the path begins and ends: a cable end, or where the core is cut and left unconnected (null: an unattached cable end). */
    start: TraceEnd | null;
    end: TraceEnd | null;
    /** Where the path really ends inside an ODP/ODC, when the outer core is terminated there. */
    start_termination: TraceTermination | null;
    end_termination: TraceTermination | null;
    core_ids: number[];
    pieces: string[];
}

export type TerminationKind = 'input' | 'output';

/** A core ending at an ODP/ODC: on a splitter input or straight on an output port. */
export interface TraceTermination {
    termination_id: number;
    core_id: number;
    location: { kind: 'odp' | 'odc'; id: number; code: string; splitter_ratio: string | null } | null;
    kind: TerminationKind;
    port: number;
    /** What the output port feeds: a customer (ODP) or an ODP (ODC). */
    customer: { id: number; name: string } | null;
    odp: { id: number; code: string } | null;
    notes: string | null;
}

/** Cores ending on an ODP's/ODC's splitter inputs and output ports, for its panel. */
export interface PanelTermination {
    kind: TerminationKind;
    port: number;
    core_id: number;
    number: number;
    cable: { id: number; code: string | null; type: CableType; cores: number; tube_size: number } | null;
}

/**
 * A point along a cable's route; `asset` is null for an end that isn't attached to anything.
 */
export interface CableRoutePoint {
    role: 'from' | 'via' | 'to';
    kind: CableEndKind | null;
    asset: { id: number; code: string } | null;
    vertex: number;
    distance_m: number;
}

/**
 * A pass-through point of the cable being drawn.
 */
export interface CableStopDraft extends CableEndpoint {
    vertex: number;
}

/**
 * A cable end attached to a POP/ODC/ODP while drawing.
 */
export interface CableEndpoint {
    type: CableEndKind;
    id: number;
    code: string;
}

/** A technician's change kept as a draft (or part of a batch waiting for approval), as the map shows it. */
export interface DraftItem {
    id: number;
    /** How other drafts refer to the record this one creates. */
    token: number;
    status: 'draft' | 'pending' | 'approved' | 'rejected' | 'cancelled';
    action: 'created' | 'updated' | 'deleted' | 'moved';
    /** odc, odp, joint, cable, customer, photo, splice, … */
    kind: string;
    route_name: string;
    subject_id: number | null;
    label: string | null;
    lat: number | null;
    lng: number | null;
    path: LatLng[] | null;
    payload: Record<string, unknown>;
    preview: { action: string; type: string; label: string; changes: Record<string, [unknown, unknown]> | null }[] | null;
    conflict: string | null;
    editable: boolean;
    /** It carries an uploaded image (a new or replacing photo). */
    has_file: boolean;
}

/** A stored change opened in its map form: a draft the technician edits, or a batch item an approver edits. */
export interface StoredChange {
    id: number;
    route_name: string;
    route_params: Record<string, string | number>;
    payload: Record<string, unknown>;
    context?: { odp_id?: number | null };
}
