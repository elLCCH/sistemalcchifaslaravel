import { Component, TemplateRef, ViewChild } from '@angular/core';
import { FormControl, FormGroup, Validators } from '@angular/forms';
import { NgbModalRef } from '@ng-bootstrap/ng-bootstrap';
import { catchError, forkJoin, map, of, switchMap } from 'rxjs';
import { environment } from 'src/environments/environment';
import { HerramientasService } from 'src/app/servicios/herramientas.service';
import { EstudiantesifasService, EstudiantesifasListParams } from 'src/app/servicios/gestiones/instituciones/tiemposinscripciones/estudiantesifas.service';
import { EventosService } from 'src/app/servicios/gestiones/instituciones/informacioneventos/eventos.service';
import { EstudianteseventosService } from 'src/app/servicios/gestiones/instituciones/informacioneventos/estudianteseventos.service';
import { AniosService } from 'src/app/servicios/gestiones/altorango/anios.service';
import { HttpEventType } from '@angular/common/http';
import JSZip from 'jszip';
import { saveAs } from 'file-saver';

type DependenciaTipo = 'DEPENDIENTE' | 'INDEPENDIENTE';
type ColumnValueType = 'text' | 'number' | 'date';

type DynamicField = {
  key: string;
  label?: string;
  type?: 'text' | 'number' | 'textarea' | 'date' | 'select';
  required?: boolean;
  options?: string[];
};

type DynamicContextSchema = {
  mode?: 'simple' | 'phases';
  phases?: Array<{ title?: string; fields?: DynamicField[] }>;
  fields?: DynamicField[];
};

type DynamicSchema = {
  version?: number;
  general?: DynamicContextSchema | null;
  byCategorias?: boolean;
  byEspecialidades?: boolean;
  categorias?: Array<{ categoria?: string; config?: DynamicContextSchema | null }>;
  especialidades?: Array<{ especialidad?: string; config?: DynamicContextSchema | null }>;
  cruces?: Array<{ especialidad?: string; categoria?: string; config?: DynamicContextSchema | null }>; // <-- ¡AÑADIR ESTO!
  phases?: Array<{ title?: string; fields?: DynamicField[] }>;
  fields?: DynamicField[];
};

type EventoCategoriaOption = {
  categoria: string;
  edades: string;
  descripcion: string;
  dependencia: DependenciaTipo;
};

type EventoEspecialidadOption = {
  especialidad: string;
  detalle: string;
  dependencia: DependenciaTipo;
};

type EventoColumnaOption = {
  title: string;
  type: ColumnValueType;
  key: string;
};

type RenderableDynamicControl = {
  key: string;
  label: string;
  type: 'text' | 'number' | 'textarea' | 'date' | 'select';
  options: string[];
};

type DynamicSection = {
  id: string;
  title: string;
  kind: 'simple' | 'phases';
  fields: DynamicField[];
  phases: Array<{ title: string; fields: DynamicField[] }>;
};

@Component({
  selector: 'app-estudianteseventos',
  standalone: false,
  templateUrl: './estudianteseventos.component.html',
  styleUrls: ['./estudianteseventos.component.scss'],
})
export class EstudianteseventosComponent {
  ruta = environment.ruta;

  eventos: any[] = [];
  eventoSeleccionadoId: number | null = null;

  registros: any[] = [];
  isLoading = false;

  filterTxt = '';
  pagin: number = 1;
  pageSize: number = 10;
  pageSizeOptions = [5, 10, 20, 50];
  filtroEdadMin: number | null = null;
  filtroEdadMax: number | null = null;
  filtroEstadoInscripcion = '';
  filtroEstadoPago = '';
  filtroTienePago: '' | 'SI' | 'NO' = '';
  mostrarFiltrosAvanzados = false;

  //#region Variables para la descarga de fotos ZIP
  // Variables generales para descargas ZIP
  isDownloadingZip = false;
  archivosDescargados = 0;
  totalArchivosADescargar = 0;
  incluirNumeroFilaEnArchivo: boolean = true;
  tipoDescargaActual: string = ''; // Mostrará 'fotos', 'certificados' o 'boletas'

  async descargarArchivosZip(tipo: 'foto' | 'certificado' | 'boleta'): Promise<void> {
    let propiedadBaseDatos = '';
    let sufijoNombre = '';
    let nombreCarpetaZip = '';

    // Configuramos los parámetros dependiendo de lo que el usuario quiere descargar
    if (tipo === 'foto') {
      propiedadBaseDatos = 'Foto';
      sufijoNombre = ''; 
      nombreCarpetaZip = `Fotos_Evento_${this.eventoSeleccionadoId}`;
      this.tipoDescargaActual = 'fotos';
    } else if (tipo === 'certificado') {
      propiedadBaseDatos = 'CertificadoNacimiento';
      sufijoNombre = ' - certNac'; // Se concatena el sufijo que pediste
      nombreCarpetaZip = `Certificados_Evento_${this.eventoSeleccionadoId}`;
      this.tipoDescargaActual = 'certificados';
    } else if (tipo === 'boleta') {
      propiedadBaseDatos = 'ComprobantePago';
      sufijoNombre = ' - Boleta'; // Se concatena el sufijo que pediste
      nombreCarpetaZip = `Boletas_Evento_${this.eventoSeleccionadoId}`;
      this.tipoDescargaActual = 'boletas';
    }

    // Filtramos solo los registros que realmente tengan ese archivo subido
    const registrosConArchivo = this.registrosFiltrados.filter(r => r?.[propiedadBaseDatos]);
    this.totalArchivosADescargar = registrosConArchivo.length;

    if (this.totalArchivosADescargar === 0) {
      this._tools.MostrarMensaje('warning', `No hay ${this.tipoDescargaActual} registrados para descargar en la vista actual.`);
      return;
    }

    this.isDownloadingZip = true;
    this.archivosDescargados = 0;
    let archivosExitosos = 0;
    
    try {
      const zip = new JSZip();
      const folder = zip.folder(nombreCarpetaZip);

      for (let i = 0; i < this.registrosFiltrados.length; i++) {
        const r = this.registrosFiltrados[i];
        const rutaArchivoLocal = r?.[propiedadBaseDatos];
        
        if (!rutaArchivoLocal) continue;

        // 1. Limpiar ruta del archivo
        const urlBase = this.ruta.endsWith('/') ? this.ruta : this.ruta + '/';
        const filePath = rutaArchivoLocal.startsWith('/') ? rutaArchivoLocal.substring(1) : rutaArchivoLocal;
        const urlArchivo = urlBase + filePath.split('/').map(segment => encodeURIComponent(segment)).join('/');

        // 2. Extraer extensión (si es foto suele ser .jpg, si es boleta podría ser .pdf)
        const extensionMatch = rutaArchivoLocal.match(/\.[0-9a-z]+$/i);
        const extension = extensionMatch ? extensionMatch[0] : '.jpg';

        // 3. Crear nombre base del estudiante
        const paterno = (r.Ap_Paterno || '').trim();
        const materno = (r.Ap_Materno || '').trim();
        const nombres = (r.Nombres || '').trim();
        let nombreBase = `${paterno} ${materno} ${nombres}`.replace(/\s+/g, ' ').trim();
        
        if (!nombreBase) nombreBase = `Estudiante_Sin_Nombre_${i + 1}`;
        nombreBase = nombreBase.replace(/[\\/:*?"<>|]/g, ''); // Evitar errores de nombres en Windows

        // 4. Armamos el nombre final según la configuración del usuario
        // Ejemplo sin fila: "Choque Choque Luis Cristian - Boleta.pdf"
        // Ejemplo con fila: "15 - Choque Choque Luis Cristian - certNac.jpg"
        let nombreConfigurado = `${nombreBase}${sufijoNombre}`;
        if (this.incluirNumeroFilaEnArchivo) {
          nombreConfigurado = `${i + 1} - ${nombreConfigurado}`;
        }

        // 5. Evitar que se chanquen archivos si dos estudiantes se llaman exactamente igual
        let finalName = `${nombreConfigurado}${extension}`;
        let counter = 1;
        while (folder?.file(finalName)) {
           finalName = `${nombreConfigurado} (${counter})${extension}`;
           counter++;
        }

        try {
          // 6. Descargar el archivo desde el servidor
          const response = await fetch(urlArchivo, { cache: 'no-cache' });
          
          if (!response.ok) {
            throw new Error(`HTTP Error: ${response.status}`);
          }

          const blob = await response.blob();
          folder?.file(finalName, blob);
          archivosExitosos++;
        } catch (err) {
          console.warn(`No se pudo descargar: ${urlArchivo}`, err);
        }
        
        this.archivosDescargados++;
      }

      // 7. Chequear si fallaron todos
      if (archivosExitosos === 0) {
        this._tools.MostrarMensaje('error', 'Error al descargar. Revisa el CORS del servidor o las rutas de los archivos.');
        this.isDownloadingZip = false;
        return;
      }

      // 8. Generar y exportar
      const zipBlob = await zip.generateAsync({ type: "blob" });
      saveAs(zipBlob, `${nombreCarpetaZip}.zip`);

      if (archivosExitosos < this.totalArchivosADescargar) {
        this._tools.MostrarMensaje('warning', `ZIP generado, pero solo se descargaron ${archivosExitosos} de ${this.totalArchivosADescargar} archivos válidos.`);
      } else {
        this._tools.MostrarMensaje('success', `Descarga de ${this.tipoDescargaActual} completada exitosamente.`);
      }

    } catch (error) {
      console.error("Error crítico al generar el ZIP", error);
      this._tools.MostrarMensaje('error', 'Ocurrió un error crítico al armar el archivo ZIP.');
    } finally {
      this.isDownloadingZip = false;
      this.archivosDescargados = 0;
      this.tipoDescargaActual = '';
    }
  }


  //#endregion variables para la descarga de fotos ZIP
  



  frm: FormGroup;
  editId: number | null = null;
  anioIFASControl = new FormControl<number | null>(null);
  aniosIFAS: any[] = [];
  inscripcionesIFAS: any[] = [];
  buscandoInscripcionIFAS = false;
  consultaIFASHecha = false;
  errorConsultaIFAS = '';
  private consultaIFASVersion = 0;
  fechaNacimientoCalculadora = new FormControl('');
  edadCalculada: number | null = null;

  // Autocompletar estudiantesifas
  estudianteInput = new FormControl('');
  mostrarListaEstudiantes = false;
  isBuscandoEstudiante = false;
  estudiantesBuscados: any[] = [];
  estudianteSeleccionado: any | null = null;

  // Dinámico
  schema: DynamicSchema | null = null;
  dynamicForm = new FormGroup({});
  dynamicSections: DynamicSection[] = [];
  dynamicValueCache: Record<string, any> = {};
  categoriaOptions: EventoCategoriaOption[] = [];
  especialidadOptions: EventoEspecialidadOption[] = [];
  eventoColumnas: EventoColumnaOption[] = [];

  @ViewChild('modalRegistro', { static: true }) modalRegistroTpl!: TemplateRef<any>;
  modalRegistroRef!: NgbModalRef;
  @ViewChild('modalEstadisticas', { static: true }) modalEstadisticasTpl!: TemplateRef<any>;
  modalEstadisticasRef!: NgbModalRef;

  constructor(
    private _eventos: EventosService,
    private _svc: EstudianteseventosService,
    private _est: EstudiantesifasService,
    private _anios: AniosService,
    protected _tools: HerramientasService,
  ) {
    this.frm = this._svc.createFormGroupestudianteseventos();
  }

  ngOnInit(): void {
    // Añadir los controles faltantes que trae el formulario público
    this.frm.addControl('Foto', new FormControl(''));
    this.frm.addControl('FechaNac', new FormControl(''));
    this.frm.addControl('Edad', new FormControl(''));
    this.frm.addControl('Tutor', new FormControl(''));
    this.frm.addControl('CelularTutor', new FormControl(''));
    this.frm.addControl('Departamento', new FormControl(''));
    this.frm.addControl('NombreInstitucion', new FormControl(''));
    this.frm.addControl('CertificadoNacimiento', new FormControl(''));

    this.cargarEventos();
    this.cargarAniosIFAS();
    this.setupBuscadorEstudiantes();
    this.frm.get('Carnet')?.valueChanges.subscribe(() => {
      if (this.editId) this.limpiarConsultaIFAS();
    });
    this.anioIFASControl.valueChanges.subscribe(() => this.limpiarConsultaIFAS());
  }



  

  // NUEVAS VARIABLES PARA CONTROL DE BÚSQUEDA Y PAGINACIÓN
  searchAplicado: string = '';
  get collectionSize(): number {
    return this.filtrarRegistros().length;
  }

  // ==========================================
  // 1. DISPARAR LA BÚSQUEDA CON ENTER
  // ==========================================
  onSearchEnter(): void {
    this.searchAplicado = (this.filterTxt ?? '').toString().trim();
    this.pagin = 1;
  }

  onPageChange(page: number): void {
    this.pagin = page;
  }

  onPageSizeChange(): void {
    this.pagin = 1;
  }

  // ==========================================
  // 2. PAGINACIÓN DESDE EL BACKEND
  // ==========================================
  cargarRegistros(): void {
    if (!this.eventoSeleccionadoId) {
      this.registros = [];
      this.isLoading = false;
      return;
    }

    this.isLoading = true;
    this.registros = [];
    const eventoId = this.eventoSeleccionadoId;
    const perPage = 100;
    this._svc.Obtenerestudianteseventos({ eventos_id: eventoId, page: 1, per_page: perPage }).pipe(
      switchMap((primeraPagina: any) => {
        const ultimaPagina = Number(primeraPagina?.last_page ?? primeraPagina?.meta?.last_page ?? 1);
        const paginasRestantes = Array.from({ length: Math.max(0, ultimaPagina - 1) }, (_, index) =>
          this._svc.Obtenerestudianteseventos({
            eventos_id: eventoId,
            page: index + 2,
            per_page: perPage,
          }),
        );
        return paginasRestantes.length
          ? forkJoin(paginasRestantes).pipe(map((restantes) => [primeraPagina, ...restantes]))
          : of([primeraPagina]);
      }),
    ).subscribe({
      next: (paginas: any[]) => {
        if (this.eventoSeleccionadoId !== eventoId) return;
        this.registros = paginas.flatMap((pagina) => pagina?.data ?? []);
        this.departamentosUnicos = this.obtenerOpcionesUnicas('Departamento');
        this.especialidadesUnicas = this.obtenerOpcionesUnicas('Especialidad');
        this.categoriasUnicas = this.obtenerOpcionesUnicas('Categoria');
        this.estadosInscripcionUnicos = this.obtenerOpcionesUnicas('EstadoInscripcion');
        this.estadosPagoUnicos = this.obtenerOpcionesUnicas('EstadoPago');
        this.isLoading = false;
      },
      error: () => {
        if (this.eventoSeleccionadoId !== eventoId) return;
        this.registros = [];
        this.isLoading = false;
        this._tools.MostrarMensajeErrordeCarga();
      },
    });
  }

  get registrosPaginados(): any[] {
    const inicio = (Math.max(1, this.pagin) - 1) * this.pageSize;
    return this.registrosFiltrados.slice(inicio, inicio + this.pageSize);
  }

  private obtenerOpcionesUnicas(campo: string): string[] {
    return [...new Set(this.registros
      .map((registro) => (registro?.[campo] ?? '').toString().trim())
      .filter(Boolean))]
      .sort((a, b) => a.localeCompare(b, 'es'));
  }

  // ==========================================
  // 3. RECOGER INFO ACTUAL AL EDITAR
  // ==========================================
  abrirEditar(row: any): void {
    const requestedId = Number(row?.id);
    if (!requestedId) return;
    this.editId = requestedId;
    this.limpiarConsultaIFAS();

    this._tools.MostrarMensajeLoading();

    // Vamos a la BD a traer el registro más reciente antes de abrir el modal
    this._svc.Seleccionarestudianteseventos(requestedId).subscribe({
      next: (res: any) => {
        if (this.editId !== requestedId) return;
        
        // Tomamos la data fresca del backend (si falla, usamos el row de la tabla por seguridad)
        const infoActual = res?.data ?? row; 

        this.limpiarEstadosDeCarga();
        this.frm.reset(this._svc.createFormGroupestudianteseventos().value);
        this.frm.patchValue({
          ...infoActual,
          FechaPago: this.fechaPagoParaFormulario(infoActual?.FechaPago),
        });
        this.fechaNacimientoCalculadora.setValue((infoActual?.FechaNac ?? '').toString().slice(0, 10), { emitEvent: false });
        this.edadCalculada = null;
        this.dynamicForm = new FormGroup({});
        this.dynamicSections = [];
        this.dynamicValueCache = this.parseDynamicValues(infoActual?.DatosEspeciales);

        this.estudianteSeleccionado = null;
        const label = `${infoActual?.Ap_Paterno ?? ''} ${infoActual?.Ap_Materno ?? ''} ${infoActual?.Nombres ?? ''} (${infoActual?.Carnet ?? ''})`;
        this.estudianteInput.setValue(label, { emitEvent: false });

        this.configurarSegunEvento();

        this.modalRegistroRef = this._tools.openModal(this.modalRegistroTpl, 'xl');
      },
      error: () => {
        if (this.editId !== requestedId) return;
        this._tools.MostrarMensajeError();
      }
    });
  }

  private setupBuscadorEstudiantes(): void {
    this.estudianteInput.valueChanges.subscribe((v) => {
      const q = (v ?? '').toString().trim();
      if (!q) {
        this.estudiantesBuscados = [];
        this.mostrarListaEstudiantes = false;
        this.estudianteSeleccionado = null;
        return;
      }
    });
  }

  cargarEventos(): void {
    this._eventos.Obtenereventos({ activos: 1 }).subscribe({
      next: (res) => {
        this.eventos = res?.data ?? [];
      },
      error: () => this._tools.MostrarMensajeErrordeCarga(),
    });
  }

  private cargarAniosIFAS(): void {
    this._anios.ObtenerAniosConPredeterminado().subscribe({
      next: (res) => {
        const aniosPorGestion = new Map<number, any>();
        (res?.anios ?? []).forEach((anio: any) => {
          const match = (anio?.Anio ?? '').toString().match(/^(\d{4})/);
          if (match && !aniosPorGestion.has(Number(match[1]))) aniosPorGestion.set(Number(match[1]), anio);
        });
        this.aniosIFAS = [...aniosPorGestion.values()].sort((a, b) =>
          Number((a?.Anio ?? '').toString().slice(0, 4)) - Number((b?.Anio ?? '').toString().slice(0, 4)),
        );

        const predeterminado = (res?.predeterminado?.Anio ?? '').toString().match(/^(\d{4})/);
        const anioDelPredeterminado = predeterminado
          ? this.aniosIFAS.find((anio) => Number((anio?.Anio ?? '').toString().slice(0, 4)) === Number(predeterminado[1]))
          : null;
        const anioInicial = anioDelPredeterminado?.id ?? this.aniosIFAS[0]?.id ?? null;
        this.anioIFASControl.setValue(anioInicial ? Number(anioInicial) : null, { emitEvent: false });
      },
      error: () => this._tools.MostrarMensajeErrordeCarga(),
    });
  }

  private limpiarConsultaIFAS(): void {
    this.consultaIFASVersion++;
    this.inscripcionesIFAS = [];
    this.buscandoInscripcionIFAS = false;
    this.consultaIFASHecha = false;
    this.errorConsultaIFAS = '';
  }

  buscarInscripcionIFAS(): void {
    const eventoId = Number(this.frm.get('eventos_id')?.value ?? 0);
    const carnet = (this.frm.get('Carnet')?.value ?? '').toString().trim();
    const anioId = Number(this.anioIFASControl.value ?? 0);

    if (!eventoId || !carnet || !anioId) {
      this._tools.MostrarMensaje('warning', 'Seleccione un año y verifique que el carnet esté completo.');
      return;
    }

    const version = ++this.consultaIFASVersion;
    this.inscripcionesIFAS = [];
    this.buscandoInscripcionIFAS = true;
    this.consultaIFASHecha = false;
    this.errorConsultaIFAS = '';
    this._svc.BuscarInscripcionIFAS(eventoId, carnet, anioId).subscribe({
      next: (res: any) => {
        if (version !== this.consultaIFASVersion) return;
        this.inscripcionesIFAS = res?.data ?? [];
        this.buscandoInscripcionIFAS = false;
        this.consultaIFASHecha = true;
      },
      error: (err) => {
        if (version !== this.consultaIFASVersion) return;
        this.buscandoInscripcionIFAS = false;
        this.errorConsultaIFAS = err?.error?.message ?? 'No se pudo verificar la inscripción IFAS.';
        this._tools.MostrarMensaje('error', this.errorConsultaIFAS);
      },
    });
  }

  fechaInscripcionIFAS(value: any): string {
    const match = (value ?? '').toString().slice(0, 10).match(/^(\d{4})-(\d{2})-(\d{2})$/);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : '—';
  }

  calcularEdadInscripcion(): void {
    const fecha = (this.fechaNacimientoCalculadora.value ?? '').toString();
    this.edadCalculada = fecha ? this._tools.CalcularEdad(new Date(`${fecha}T00:00:00`)) : null;
  }

  fechaTabla(value: any): string {
    return this.fechaInscripcionIFAS(value);
  }

  onChangeEvento(): void {
    this.pagin = 1;
    this.limpiarFiltros();
    this.cargarRegistros();
  }

  // NUEVAS VARIABLES PARA LOS FILTROS
  // ==========================================
  filtroDepartamento = '';
  filtroEspecialidad = '';
  filtroCategoria = '';

// =========================================
  // FILTROS MÚLTIPLES (ARRAYS)
  // ==========================================
  filtroDepartamentos: string[] = [];
  filtroEspecialidades: string[] = [];
  filtroCategorias: string[] = [];
  filtroEstadosInscripcion: string[] = [];
  filtroEstadosPago: string[] = [];

  departamentosUnicos: string[] = [];
  especialidadesUnicas: string[] = [];
  categoriasUnicas: string[] = [];
  estadosInscripcionUnicos: string[] = [];
  estadosPagoUnicos: string[] = [];

  // ==========================================
  // CONTROL DE VISIBILIDAD DE COLUMNAS
  // ==========================================
  columnasVisibles = {
    foto: false,
    paterno: true,
    materno: true,
    nombres: true,
    carnet: true,
    edad: true,
    fechaNac: false,
    celular: false,
    celularTutor: false,
    fechaInscripcion: false,
    nombreInstitucion: false,
    departamento: false,
    especialidad: true,
    categoria: true,
    certNac: false,
    boleta: false,
    estadoInscripcion: true,
    tienePago: false, // NUEVO
    monto: false,     // NUEVO
    estadoPago: true,
    correo: true
  };

 
  onFiltroSelectChange(): void {
    this.pagin = 1;
  }

  // MÉTODO MEJORADO: Alternar filtros múltiples
  toggleFiltro(tipo: string, valor: string): void {
    if (tipo === 'tienePago') {
      this.filtroTienePago = this.filtroTienePago === valor ? '' : (valor as any);
      this.onFiltroSelectChange();
      return;
    }

    let currentArray: string[] = [];
    switch (tipo) {
      case 'departamento': currentArray = this.filtroDepartamentos; break;
      case 'especialidad': currentArray = this.filtroEspecialidades; break;
      case 'categoria': currentArray = this.filtroCategorias; break;
      case 'estadoInscripcion': currentArray = this.filtroEstadosInscripcion; break;
      case 'estadoPago': currentArray = this.filtroEstadosPago; break;
    }

    if (valor === '') {
      currentArray = []; // Limpiar todos
    } else {
      const index = currentArray.indexOf(valor);
      if (index > -1) {
        currentArray = currentArray.filter(v => v !== valor); // Quitar si ya estaba seleccionado
      } else {
        currentArray = [...currentArray, valor]; // Agregar si no estaba
      }
    }

    switch (tipo) {
      case 'departamento': this.filtroDepartamentos = currentArray; break;
      case 'especialidad': this.filtroEspecialidades = currentArray; break;
      case 'categoria': this.filtroCategorias = currentArray; break;
      case 'estadoInscripcion': this.filtroEstadosInscripcion = currentArray; break;
      case 'estadoPago': this.filtroEstadosPago = currentArray; break;
    }

    this.onFiltroSelectChange();
  }

  onFilterTxtChange(): void {
    this.pagin = 1;
  }

  buscarEstudiante(): void {
    const q = (this.estudianteInput.value ?? '').toString().trim();
    if (!q) return;

    this.isBuscandoEstudiante = true;
    this.mostrarListaEstudiantes = true;

    const params: EstudiantesifasListParams = {
      page: 1,
      per_page: 10,
      search: q,
      search_mode: 'any',
      relevance: true,
    };

    this._est
      .Obtenerestudiantesifas(params)
      .pipe(
        catchError(() => {
          this.isBuscandoEstudiante = false;
          this.estudiantesBuscados = [];
          return of({ data: [] });
        }),
      )
      .subscribe((res) => {
        this.isBuscandoEstudiante = false;
        this.estudiantesBuscados = res?.data ?? [];
      });
  }

  seleccionarEstudiante(row: any): void {
    this.estudianteSeleccionado = row;
    this.mostrarListaEstudiantes = false;

    this.frm.patchValue({
      estudiantesifas_id: row?.id ?? null,
      Ap_Paterno: row?.Ap_Paterno ?? '',
      Ap_Materno: row?.Ap_Materno ?? '',
      Nombres: row?.Nombre ?? '',
      Carnet: row?.CI ?? row?.Carnet ?? '',
      Celular: row?.Celular ?? '',
      Correo: row?.Correo ?? '',
    });

    const label = `${row?.Ap_Paterno ?? ''} ${row?.Ap_Materno ?? ''} ${row?.Nombre ?? ''} (${row?.CI ?? ''})`;
    this.estudianteInput.setValue(label, { emitEvent: false });
  }

  abrirNuevo(): void {
    if (!this.eventoSeleccionadoId) {
      this._tools.MostrarMensaje('warning', 'Seleccione un evento');
      return;
    }
    this.limpiarEstadosDeCarga();
    this.limpiarConsultaIFAS();
    this.fechaNacimientoCalculadora.setValue('', { emitEvent: false });
    this.edadCalculada = null;

    this.editId = null;
    this.frm.reset(this._svc.createFormGroupestudianteseventos().value);
    this.dynamicForm = new FormGroup({});
    this.dynamicSections = [];
    this.estudianteInput.setValue('', { emitEvent: false });
    this.estudianteSeleccionado = null;
    this.dynamicValueCache = {};

    this.frm.patchValue({
      eventos_id: this.eventoSeleccionadoId,
      EstadoInscripcion: 'PENDIENTE',
      EstadoPago: 'NO_APLICA',
      TienePago: 0,
    });

    this.configurarSegunEvento();

    this.modalRegistroRef = this._tools.openModal(this.modalRegistroTpl, 'xl');
  }

  limpiarEstadosDeCarga() {
    this.isUploadingFoto = false; this.uploadProgressFoto = 0;
    this.isUploadingComprobante = false; this.uploadProgressComprobante = 0;
    this.isUploadingCertificado = false; this.uploadProgressCertificado = 0;
  }

  // =======================================================
  // LOGICA DE DRAG & DROP Y SUBIDA DE ARCHIVOS (ADMIN)
  // =======================================================

  private readonly MAX_FILE_SIZE_MB = 5;
  private readonly MAX_FILE_SIZE_BYTES = this.MAX_FILE_SIZE_MB * 1024 * 1024;

  isDraggingFoto = false; isUploadingFoto = false; uploadProgressFoto = 0;
  isDraggingComprobante = false; isUploadingComprobante = false; uploadProgressComprobante = 0;
  isDraggingCertificado = false; isUploadingCertificado = false; uploadProgressCertificado = 0;

  abrirSelector(input: HTMLInputElement): void { input.click(); }

  esArchivoImagen(valor: string | null | undefined): boolean {
    if (!valor) return false;
    return /\.(png|jpe?g|gif|webp|bmp|svg)$/i.test(valor.toString().toLowerCase());
  }

  // EVENTOS FOTO
  onFotoDragOver(event: DragEvent): void { event.preventDefault(); this.isDraggingFoto = true; }
  onFotoDragLeave(event: DragEvent): void { event.preventDefault(); this.isDraggingFoto = false; }
  onFotoDrop(event: DragEvent): void { event.preventDefault(); this.isDraggingFoto = false; if (event.dataTransfer?.files) this.manejarArchivo(event.dataTransfer.files, 'Foto'); }
  onFotoFileSelected(event: Event): void { const i = event.target as HTMLInputElement; if (i.files) this.manejarArchivo(i.files, 'Foto'); i.value = ''; }

  // EVENTOS COMPROBANTE
  onComprobanteDragOver(event: DragEvent): void { event.preventDefault(); this.isDraggingComprobante = true; }
  onComprobanteDragLeave(event: DragEvent): void { event.preventDefault(); this.isDraggingComprobante = false; }
  onComprobanteDrop(event: DragEvent): void { event.preventDefault(); this.isDraggingComprobante = false; if (event.dataTransfer?.files) this.manejarArchivo(event.dataTransfer.files, 'Comprobante'); }
  onComprobanteFileSelected(event: Event): void { const i = event.target as HTMLInputElement; if (i.files) this.manejarArchivo(i.files, 'Comprobante'); i.value = ''; }

  // EVENTOS CERTIFICADO
  onCertificadoDragOver(event: DragEvent): void { event.preventDefault(); this.isDraggingCertificado = true; }
  onCertificadoDragLeave(event: DragEvent): void { event.preventDefault(); this.isDraggingCertificado = false; }
  onCertificadoDrop(event: DragEvent): void { event.preventDefault(); this.isDraggingCertificado = false; if (event.dataTransfer?.files) this.manejarArchivo(event.dataTransfer.files, 'Certificado'); }
  onCertificadoFileSelected(event: Event): void { const i = event.target as HTMLInputElement; if (i.files) this.manejarArchivo(i.files, 'Certificado'); i.value = ''; }

  private manejarArchivo(files: FileList, tipo: 'Foto' | 'Comprobante' | 'Certificado'): void {
    const file = files.item(0);
    if (!file) return;
    if (file.size > this.MAX_FILE_SIZE_BYTES) {
      this._tools.MostrarMensaje('error', `El archivo es muy pesado. Máximo ${this.MAX_FILE_SIZE_MB} MB.`);
      return; 
    }
    this.procesarSubida(file, tipo);
  }

  private procesarSubida(file: File, tipo: 'Foto' | 'Comprobante' | 'Certificado'): void {
    let formControlName = ''; let carpetaDestino = '';

    if (tipo === 'Foto') { 
      this.isUploadingFoto = true; this.uploadProgressFoto = 0; formControlName = 'Foto'; carpetaDestino = 'FotoParticipantes';
    } else if (tipo === 'Comprobante') {
      this.isUploadingComprobante = true; this.uploadProgressComprobante = 0; formControlName = 'ComprobantePago'; carpetaDestino = 'ComprobantePago';
    } else if (tipo === 'Certificado') {
      this.isUploadingCertificado = true; this.uploadProgressCertificado = 0; formControlName = 'CertificadoNacimiento'; carpetaDestino = 'CertificadoNacimiento';
    }

    this._tools.SubirArchivoPublico(file, carpetaDestino).subscribe({
      next: (event) => {
        if (event.type === HttpEventType.UploadProgress && event.total) {
          const p = Math.round((event.loaded / event.total) * 100);
          if (tipo === 'Foto') this.uploadProgressFoto = p;
          else if (tipo === 'Comprobante') this.uploadProgressComprobante = p;
          else this.uploadProgressCertificado = p;
          return;
        }
        if (event.type === HttpEventType.Response) {
          const filePath: string | undefined = (event.body as any)?.filePath;
          if (filePath) this.frm.patchValue({ [formControlName]: filePath });
          if (tipo === 'Foto') this.isUploadingFoto = false;
          else if (tipo === 'Comprobante') this.isUploadingComprobante = false;
          else this.isUploadingCertificado = false;
        }
      },
      error: () => {
        this._tools.MostrarMensajeError();
        if (tipo === 'Foto') this.isUploadingFoto = false;
        else if (tipo === 'Comprobante') this.isUploadingComprobante = false;
        else this.isUploadingCertificado = false;
      }
    });
  }

  private getEventoSeleccionado(): any | null {
    const id = this.frm.get('eventos_id')?.value ?? this.eventoSeleccionadoId;
    const num = id ? Number(id) : null;
    if (!num) return null;
    return (this.eventos ?? []).find((e) => Number(e?.id) === num) ?? null;
  }

  private parseSchema(value: any): DynamicSchema | null {
    const raw = (value ?? '').toString().trim();
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  private normalizarBusqueda(value: any): string {
    return (value ?? '').toString().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  }

  edadRegistro(row: any): number | null {
    const raw = row?.Edad;
    if (raw !== null && raw !== undefined && raw.toString().trim() !== '') {
      const age = Number(raw);
      if (Number.isFinite(age) && age >= 0) return Math.floor(age);
    }
    const date = (row?.FechaNac ?? '').toString().trim();
    if (!date) return null;
    const birth = new Date(`${date.substring(0, 10)}T00:00:00`);
    if (!Number.isFinite(birth.getTime())) return null;
    const today = new Date();
    let age = today.getFullYear() - birth.getFullYear();
    if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) age--;
    return age >= 0 ? age : null;
  }

  // aplicarOrdenamiento(): void {
  //   if (!this.sortColumn) return;

  //   this.registrosFiltrados.sort((a, b) => {
  //     const valorA = a[this.sortColumn];
  //     const valorB = b[this.sortColumn];

  //     // Manejo de valores vacíos o nulos (los manda siempre al final)
  //     if ((valorA == null || valorA === '') && (valorB != null && valorB !== '')) return this.sortDirection === 'asc' ? 1 : -1;
  //     if ((valorA != null && valorA !== '') && (valorB == null || valorB === '')) return this.sortDirection === 'asc' ? -1 : 1;
  //     if (valorA === valorB) return 0;

  //     // Detectar si son números para ordenarlos matemáticamente y no alfabéticamente
  //     const esNumero = !isNaN(Number(valorA)) && !isNaN(Number(valorB)) && valorA !== '' && valorB !== '';
      
  //     let cmp = 0;
  //     if (esNumero) {
  //       cmp = Number(valorA) - Number(valorB);
  //     } else {
  //       cmp = String(valorA).localeCompare(String(valorB), undefined, { numeric: true, sensitivity: 'base' });
  //     }

  //     return this.sortDirection === 'asc' ? cmp : -cmp;
  //   });

  //   this.pagin = 1; // Resetea a la primera página tras ordenar
  // }

  indiceFila(index: number): number {
    return (Math.max(1, this.pagin) - 1) * this.pageSize + index + 1;
  }

  private parseJsonAny(value: any): any | null {
    const raw = (value ?? '').toString().trim();
    if (!raw) return null;
    try {
      return JSON.parse(raw);
    } catch {
      return null;
    }
  }

  private normalizeOptionKey(value: any): string {
    return (value ?? '').toString().trim().toUpperCase();
  }

  private normalizeDependencia(value: any): DependenciaTipo {
    return this.normalizeOptionKey(value) === 'INDEPENDIENTE' ? 'INDEPENDIENTE' : 'DEPENDIENTE';
  }

  private normalizeColumnType(value: any): ColumnValueType {
    const type = (value ?? '').toString().trim().toLowerCase();
    return type === 'number' || type === 'date' ? type : 'text';
  }

  private toSchemaKey(value: any): string {
    return (value ?? '').toString().trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  }

  private filtrarRegistros(): any[] {
    let filtered = [...(this.registros ?? [])];
    const query = this.normalizarBusqueda(this.searchAplicado).trim();
    if (query) {
      const tokens = query.split(/\s+/).filter(Boolean);
      filtered = filtered.filter((row) => {
        const dynamicValues = Object.values(this.parseDynamicValues(row?.DatosEspeciales));
        const searchable = [
          row?.id, row?.Ap_Paterno, row?.Ap_Materno, row?.Nombres, row?.Carnet,
          row?.Celular, row?.Correo, row?.Departamento, row?.NombreInstitucion,
          row?.Especialidad, row?.Categoria, row?.EstadoInscripcion, row?.EstadoPago,
          row?.MetodoPago, row?.Monto, row?.Observacion, this.edadRegistro(row), ...dynamicValues,
        ].map((value) => this.normalizarBusqueda(value)).join(' ');
        return tokens.every((token) => searchable.includes(token));
      });
    }

    // Modifica esta parte dentro de filtrarRegistros()
    if (this.filtroDepartamentos.length > 0) filtered = filtered.filter((row) => this.filtroDepartamentos.includes((row?.Departamento ?? '').toString().trim()));
    if (this.filtroEspecialidades.length > 0) filtered = filtered.filter((row) => this.filtroEspecialidades.includes((row?.Especialidad ?? '').toString().trim()));
    if (this.filtroCategorias.length > 0) filtered = filtered.filter((row) => this.filtroCategorias.includes((row?.Categoria ?? '').toString().trim()));
    if (this.filtroEstadosInscripcion.length > 0) filtered = filtered.filter((row) => this.filtroEstadosInscripcion.includes((row?.EstadoInscripcion ?? '').toString().trim()));
    if (this.filtroEstadosPago.length > 0) filtered = filtered.filter((row) => this.filtroEstadosPago.includes((row?.EstadoPago ?? '').toString().trim()));
    
    const minAge = this.filtroEdadMin === null || this.filtroEdadMin === undefined ? null : Number(this.filtroEdadMin);
    const maxAge = this.filtroEdadMax === null || this.filtroEdadMax === undefined ? null : Number(this.filtroEdadMax);
    if ((minAge !== null && Number.isFinite(minAge)) || (maxAge !== null && Number.isFinite(maxAge))) {
      filtered = filtered.filter((row) => {
        const age = this.edadRegistro(row);
        return age !== null && (minAge === null || !Number.isFinite(minAge) || age >= minAge)
          && (maxAge === null || !Number.isFinite(maxAge) || age <= maxAge);
      });
    }
    return filtered;
  }


  abrirModalEstadisticas(): void {
    if (!this.eventoSeleccionadoId) return;
    this.modalEstadisticasRef = this._tools.openModal(this.modalEstadisticasTpl, 'xl');
  }

  private contarPor(selector: (row: any) => any): Array<{ label: string; total: number }> {
    const counts = new Map<string, number>();
    for (const row of this.registros) {
      const label = (selector(row) ?? '').toString().trim() || 'Sin dato';
      counts.set(label, (counts.get(label) ?? 0) + 1);
    }
    return [...counts.entries()].map(([label, total]) => ({ label, total }))
      .sort((a, b) => b.total - a.total || a.label.localeCompare(b.label, 'es'));
  }

  get estadisticasEvento(): any {
    const edades = [
      { label: '0-5 años', min: 0, max: 5, total: 0 },
      { label: '6-9 años', min: 6, max: 9, total: 0 },
      { label: '10-12 años', min: 10, max: 12, total: 0 },
      { label: '13-15 años', min: 13, max: 15, total: 0 },
      { label: '16-18 años', min: 16, max: 18, total: 0 },
      { label: '19-25 años', min: 19, max: 25, total: 0 },
      { label: '26 o más', min: 26, max: Number.MAX_SAFE_INTEGER, total: 0 },
      { label: 'Sin edad', min: 0, max: 0, total: 0 },
    ];
    for (const row of this.registros) {
      const age = this.edadRegistro(row);
      const bucket = age === null ? edades[7] : edades.find((item) => age >= item.min && age <= item.max);
      if (bucket) bucket.total++;
    }
    return {
      total: this.registros.length,
      pagos: this.registros.filter((row) => Number(row?.TienePago) === 1).length,
      sinPago: this.registros.filter((row) => Number(row?.TienePago) !== 1).length,
      categorias: this.contarPor((row) => row?.Categoria),
      especialidades: this.contarPor((row) => row?.Especialidad),
      departamentos: this.contarPor((row) => row?.Departamento),
      instituciones: this.contarPor((row) => row?.NombreInstitucion),
      estados: this.contarPor((row) => row?.EstadoInscripcion),
      pagosPorEstado: this.contarPor((row) => Number(row?.TienePago) === 1 ? row?.EstadoPago : 'No aplica'),
      edades: edades.filter((bucket) => bucket.total > 0),
    };
  }

  limpiarFiltros(): void {
    this.filterTxt = '';
    this.searchAplicado = '';
    this.filtroDepartamentos = [];
    this.filtroEspecialidades = [];
    this.filtroCategorias = [];
    this.filtroEstadosInscripcion = [];
    this.filtroEstadosPago = [];
    this.filtroTienePago = '';
    this.filtroEdadMin = null;
    this.filtroEdadMax = null;
    this.criteriosOrden.forEach((criterio) => {
      criterio.columna = '';
      criterio.direccion = 'asc';
    });
    this.pagin = 1;
  }

  private hoverAccion(key: string, visible: boolean): void {
    this._tools.hoverBotonMap[key] = visible;
  }

  private get reportRecords(): any[] {
    return this.registrosFiltrados;
  }

  private get reportDynamicKeys(): string[] {
    return [...new Set(this.reportRecords.flatMap((row) => Object.keys(this.parseDynamicValues(row?.DatosEspeciales))))];
  }

  valorDatoEspecialReporte(row: any, key: string): string {
    const value = this.parseDynamicValues(row?.DatosEspeciales)[key];
    if (value === null || value === undefined || value === '') return '';
    return Array.isArray(value) ? value.join(', ') : String(value).replace(/\r?\n|\r/g, ' / ');
  }

  private extractEventoCategorias(value: any): EventoCategoriaOption[] {
    const parsed = this.parseJsonAny(value);
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') return [];

    const rawCategorias = Array.isArray(parsed?.categorias)
      ? parsed.categorias
      : Array.isArray(parsed?.Categorias)
        ? parsed.Categorias
        : [];

    const seen = new Set<string>();
    return rawCategorias
      .map((item: any) => ({
        categoria: (item?.Categoria ?? item?.categoria ?? '').toString().trim(),
        edades: (item?.Edades ?? item?.edades ?? '').toString().trim(),
        descripcion: (item?.Descripcion ?? item?.descripcion ?? '').toString().trim(),
        dependencia: this.normalizeDependencia(item?.Dependencia ?? item?.dependencia),
      }))
      .filter((item) => {
        if (!item.categoria) return false;
        const key = item.categoria.toUpperCase();
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  }

  private extractEventoEspecialidades(value: any): EventoEspecialidadOption[] {
    const parsed = this.parseJsonAny(value);
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') return [];

    const rawEspecialidades = Array.isArray(parsed?.especialidades)
      ? parsed.especialidades
      : Array.isArray(parsed?.Especialidades)
        ? parsed.Especialidades
        : [];

    const seen = new Set<string>();
    return rawEspecialidades
      .map((item: any) => ({
        especialidad: (item?.Especialidad ?? item?.especialidad ?? '').toString().trim(),
        detalle: (item?.Detalle ?? item?.detalle ?? '').toString().trim(),
        dependencia: this.normalizeDependencia(item?.Dependencia ?? item?.dependencia),
      }))
      .filter((item) => {
        if (!item.especialidad) return false;
        const key = item.especialidad.toUpperCase();
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });
  }

  private extractEventoColumnas(value: any): EventoColumnaOption[] {
    const parsed = this.parseJsonAny(value);
    if (!parsed || Array.isArray(parsed) || typeof parsed !== 'object') return [];

    const rawColumnas = Array.isArray(parsed?.columnas)
      ? parsed.columnas
      : Array.isArray(parsed?.Columnas)
        ? parsed.Columnas
        : [];

    const seen = new Set<string>();
    return rawColumnas
      .map((item: any, index: number) => {
        const title = (item?.Titulo ?? item?.titulo ?? '').toString().trim();
        const key = this.toSchemaKey(title) || `col_${index + 1}`;
        return {
          title,
          type: this.normalizeColumnType(item?.Tipo ?? item?.tipo),
          key,
        };
      })
      .filter((item) => {
        if (!item.title) return false;
        if (seen.has(item.key)) return false;
        seen.add(item.key);
        return true;
      });
  }

  get categoriaSeleccionada(): EventoCategoriaOption | null {
    const value = (this.frm.get('Categoria')?.value ?? '').toString().trim();
    if (!value) return null;
    return this.categoriaOptions.find((item) => item.categoria.toUpperCase() === value.toUpperCase()) ?? null;
  }

  get especialidadSeleccionada(): EventoEspecialidadOption | null {
    const value = (this.frm.get('Especialidad')?.value ?? '').toString().trim();
    if (!value) return null;
    return this.especialidadOptions.find((item) => item.especialidad.toUpperCase() === value.toUpperCase()) ?? null;
  }

  onCategoriaChange(): void {
    this.rebuildDynamicForm();
  }

  onEspecialidadChange(): void {
    this.rebuildDynamicForm();
  }

  private configurarSegunEvento(): void {
    const ev = this.getEventoSeleccionado();
    if (!this.editId) {
      const tienePago = ev?.TienePago == 1 ? 1 : 0;
      this.frm.patchValue({
        TienePago: tienePago,
        Monto: tienePago ? (ev?.Monto ?? null) : null,
        EstadoPago: tienePago ? (this.frm.get('EstadoPago')?.value || 'PENDIENTE') : 'NO_APLICA',
      });
    }

    this.categoriaOptions = this.extractEventoCategorias(ev?.Parametros);
    this.especialidadOptions = this.extractEventoEspecialidades(ev?.Especialidades);
    console.log("lcch verificando", this.categoriaOptions);
    
    this.eventoColumnas = this.extractEventoColumnas(ev?.Columnas);
    this.syncOptionControl('Categoria', this.categoriaOptions.map((item) => item.categoria));
    this.syncOptionControl('Especialidad', this.especialidadOptions.map((item) => item.especialidad));

    this.schema = this.parseSchema(ev?.InputsEspecial);
    this.rebuildDynamicForm();
  }

  private syncOptionControl(controlName: string, options: string[]): void {
    const control = this.frm.get(controlName);
    if (!control) return;

    control.setValidators(options.length > 0 ? [Validators.required] : []);
    const current = (control.value ?? '').toString().trim();
    const match = options.find((item) => this.normalizeOptionKey(item) === this.normalizeOptionKey(current));

    if (options.length === 1 && !current) {
      control.setValue(options[0], { emitEvent: false });
    } else if (options.length > 0 && current && !match) {
      control.setValue('', { emitEvent: false });
    } else if (match && match !== current) {
      control.setValue(match, { emitEvent: false });
    } else if (options.length === 0 && !this.editId) {
      control.setValue('', { emitEvent: false });
    }

    control.updateValueAndValidity({ emitEvent: false });
  }

  get usaColumnasEnInputs(): boolean {
    return this.eventoColumnas.length > 0;
  }

  private normalizeRenderableType(type: any): 'text' | 'number' | 'textarea' | 'date' | 'select' {
    if (type === 'number' || type === 'textarea' || type === 'date' || type === 'select') return type;
    return 'text';
  }

  private buildDynamicControlKey(fieldKey: string, column: EventoColumnaOption, index: number): string {
    return index === 0 ? fieldKey : `${fieldKey}__${column.key}`;
  }

  getFieldControls(field: any): RenderableDynamicControl[] {
    const fieldKey = (field?.key ?? '').toString().trim();
    if (!fieldKey) return [];

    // 🔥 SOLUCIÓN AL BUG: Si ya calculamos los controles para este campo, 
    // devolvemos los mismos para no perder la referencia de memoria.
    if (field._cachedControls) {
      return field._cachedControls;
    }

    let controls: RenderableDynamicControl[];

    if (this.eventoColumnas.length === 0) {
      controls = [
        {
          key: fieldKey,
          label: (field?.label ?? fieldKey).toString(),
          type: this.normalizeRenderableType(field?.type),
          options: Array.isArray(field?.options) ? field.options : [],
        },
      ];
    } else {
      controls = this.eventoColumnas.map((column, index) => ({
        key: this.buildDynamicControlKey(fieldKey, column, index),
        label: column.title,
        type: column.type,
        options: [],
      }));
    }

    // 🔥 Guardamos los controles calculados en el propio objeto field
    field._cachedControls = controls;
    return controls;
  }

  private addDynamicControl(controlKey: string, required: boolean): void {
    const validators = required ? [Validators.required] : [];
    this.dynamicForm.addControl(controlKey, new FormControl('', validators));
  }

  private buildSectionFromConfig(id: string, title: string, raw: any): DynamicSection | null {
    if (!raw || typeof raw !== 'object') return null;

    const phases = Array.isArray(raw?.phases)
      ? raw.phases
          .map((phase: any, index: number) => ({
            title: (phase?.title ?? `Fase ${index + 1}`).toString(),
            fields: (phase?.fields ?? []).filter((field: any) => !!field?.key),
          }))
          .filter((phase: any) => phase.fields.length > 0)
      : [];

    if (phases.length > 0) {
      return { id, title, kind: 'phases', fields: [], phases };
    }

    const fields = Array.isArray(raw?.fields) ? raw.fields.filter((field: any) => !!field?.key) : [];
    if (fields.length > 0) {
      return { id, title, kind: 'simple', fields, phases: [] };
    }

    return null;
  }

  private resolveDynamicSections(schema: DynamicSchema | null): DynamicSection[] {
    if (!schema) return [];

    // Verificamos si es V2 o tiene alguna de las nuevas propiedades
    const isV2 = !!schema.general || !!schema.categorias || !!schema.especialidades || !!schema.cruces || (schema.version ?? 0) >= 2;
    if (!isV2) {
      const legacy = this.buildSectionFromConfig('general', 'General / Dependiente', schema);
      return legacy ? [legacy] : [];
    }

    const sections: DynamicSection[] = [];
    const general = this.buildSectionFromConfig('general', 'General / Dependiente', schema.general ?? null);
    if (general) sections.push(general);

    // OJO: En el administrador usamos this.frm para obtener los valores
    const categoriaSeleccionada = (this.frm.get('Categoria')?.value ?? '').toString().trim();
    const especialidadSeleccionada = (this.frm.get('Especialidad')?.value ?? '').toString().trim();

    let usoCruce = false;

    // 1. NUEVA LÓGICA: Buscar el "Cruce" si el evento exige ambos
    if (schema.byCategorias && schema.byEspecialidades) {
      if (categoriaSeleccionada && especialidadSeleccionada) {
        const cruceConfig = (schema.cruces ?? []).find(
          (item) => 
            this.normalizeOptionKey(item?.especialidad ?? '') === this.normalizeOptionKey(especialidadSeleccionada) &&
            this.normalizeOptionKey(item?.categoria ?? '') === this.normalizeOptionKey(categoriaSeleccionada)
        );
        const section = this.buildSectionFromConfig(
          `cruce:${especialidadSeleccionada}_${categoriaSeleccionada}`, 
          `${especialidadSeleccionada} - ${categoriaSeleccionada}`, 
          cruceConfig?.config ?? null
        );
        if (section) {
          sections.push(section);
          usoCruce = true; // Marcamos que se usó un cruce
        }
      }
    } 
    
    // 2. LÓGICA ANTERIOR: Si no hubo cruce, cargamos las individuales
    if (!usoCruce) {
      if (schema.byCategorias && categoriaSeleccionada) {
        const categoryConfig = (schema.categorias ?? []).find(
          (item) => this.normalizeOptionKey(item?.categoria ?? '') === this.normalizeOptionKey(categoriaSeleccionada),
        );
        const section = this.buildSectionFromConfig(
          `cat:${categoriaSeleccionada}`,
          `Categoría: ${categoriaSeleccionada}`,
          categoryConfig?.config ?? null,
        );
        if (section) sections.push(section);
      }

      if (schema.byEspecialidades && especialidadSeleccionada) {
        const specialtyConfig = (schema.especialidades ?? []).find(
          (item) => this.normalizeOptionKey(item?.especialidad ?? '') === this.normalizeOptionKey(especialidadSeleccionada),
        );
        const section = this.buildSectionFromConfig(
          `esp:${especialidadSeleccionada}`,
          `Especialidad: ${especialidadSeleccionada}`,
          specialtyConfig?.config ?? null,
        );
        if (section) sections.push(section);
      }
    }

    return sections;
  }
  
  private rebuildDynamicForm(): void {
    this.dynamicValueCache = { ...this.dynamicValueCache, ...(this.dynamicForm?.value ?? {}) };
    this.dynamicSections = this.resolveDynamicSections(this.schema);
    this.dynamicForm = new FormGroup({});

    const seen = new Set<string>();
    this.dynamicSections.forEach((section) => {
      const fields = section.kind === 'phases' ? section.phases.flatMap((phase) => phase.fields) : section.fields;
      fields.forEach((field) => {
        this.getFieldControls(field).forEach((control) => {
          const key = (control?.key ?? '').toString().trim();
          if (!key || seen.has(key)) return;
          seen.add(key);
          this.addDynamicControl(key, !!field?.required);
        });
      });
    });

    Object.entries(this.dynamicValueCache).forEach(([key, value]) => {
      if (this.dynamicForm.get(key)) {
        this.dynamicForm.get(key)?.setValue(value, { emitEvent: false });
      }
    });
  }

  private parseDynamicValues(raw: any): Record<string, any> {
    if (!raw) return {};

    try {
      const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
      return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
      return {};
    }
  }

  get tienePagoSeleccionado(): boolean {
    return this.frm.get('TienePago')?.value == 1;
  }

  private fechaPagoParaFormulario(value: any): string {
    const fecha = (value ?? '').toString().trim();
    const match = fecha.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    return match ? `${match[1]}T${match[2]}` : '';
  }

  private fechaPagoParaApi(value: any): string | null {
    const fecha = (value ?? '').toString().trim();
    if (!fecha) return null;

    const match = fecha.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2}))?$/);
    if (!match) return fecha;

    return `${match[1]} ${match[2]}:${match[3] ?? '00'}`;
  }

  private buildPayload(): any {
    const dynamicData = this.dynamicForm.value;
    const payload = { ...this.frm.value };
    payload.DatosEspeciales = JSON.stringify(dynamicData ?? {});
    payload.Especialidad = (payload.Especialidad ?? '').toString().trim();
    payload.Categoria = (payload.Categoria ?? '').toString().trim();
    payload.FechaPago = this.fechaPagoParaApi(payload.FechaPago);

    if (!payload.TienePago || payload.TienePago == 0) {
      payload.Monto = null;
      payload.MetodoPago = '';
      payload.FechaPago = null;
      payload.ComprobantePago = '';
      payload.EstadoPago = 'NO_APLICA';
    } else {
      // si tiene pago y no se puso estado, dejar en PENDIENTE
      if (!payload.EstadoPago) payload.EstadoPago = 'PENDIENTE';
    }

    return payload;
  }

  guardar(): void {
    if (this.frm.invalid) {
      this.ValidacionestudianteseventosTodoElemento();
      this._tools.MostrarMensajeFrmIncompleto();
      return;
    }

    if (this.dynamicForm.invalid) {
      Object.values(this.dynamicForm.controls).forEach((control: any) => {
        control.markAsTouched();
        control.markAsDirty();
      });
      this._tools.MostrarMensaje('warning', 'Complete los campos del diseño especial');
      return;
    }

    const payload = this.buildPayload();

    this._tools.MostrarMensajeLoading();

    const req = this.editId ? this._svc.Modificarestudianteseventos(payload, this.editId) : this._svc.Agregarestudianteseventos(payload);
    req.subscribe({
      next: () => {
        this.modalRegistroRef?.close();
        this._tools.MostrarMensajeSuccess();
        this.cargarRegistros();
      },
      error: () => this._tools.MostrarMensajeError(),
    });
  }

  async eliminar(row: any): Promise<void> {
    const ok = await this._tools.mostrarMensajeConfirm('Eliminar inscripción', '¿Está seguro?', 'warning', 'Sí, eliminar', 'Cancelar');
    if (!ok) return;

    this._svc.Eliminarestudianteseventos(row?.id).subscribe({
      next: () => {
        this._tools.MostrarMensajeSuccess();
        this.cargarRegistros();
      },
      error: () => this._tools.MostrarMensajeError(),
    });
  }

  async generarCertificado(row: any): Promise<void> {
    const ok = await this._tools.mostrarMensajeConfirm(
      'Generar certificado',
      'Se generará un PDF usando el diseño asignado al evento. ¿Continuar?',
      'info',
      'Sí, generar',
      'Cancelar',
    );
    if (!ok) return;

    this._tools.MostrarMensajeLoading();
    this._svc.GenerarCertificado(row?.id).subscribe({
      next: (res) => {
        this._tools.MostrarMensajeSuccess();
        const url = res?.data?.url;
        if (url) window.open(url, '_blank');
        this.cargarRegistros();
      },
      error: (err) => {
        const msg = err?.error?.error || 'No se pudo generar el certificado';
        this._tools.MostrarMensaje('error', msg);
      },
    });
  }

  ValidacionestudianteseventosxElemento(elemento: string): string {
    return this._tools.obtenerValidationClass(this.frm, elemento);
  }

  ValidacionestudianteseventosTodoElemento(): void {
    Object.values(this.frm.controls).forEach((control) => {
      control.markAsTouched();
      control.markAsDirty();
    });
  }


  // Función vital para que Angular no pierda el foco de los inputs
  trackByKey(index: number, item: any): string | number {
    return item?.key || item?.id || index;
  }
  // Referencias para el reporte
  @ViewChild('modalReporte', { static: true }) modalReporteTpl!: TemplateRef<any>;
  modalReporteRef!: NgbModalRef;

  reporteHeaders: string[] = [];
  reporteRows: any[][] = [];
  // =======================================================
  // LOGICA DEL REPORTE
  // =======================================================

  abrirReporte(): void {
    if (!this.eventoSeleccionadoId || this.collectionSize === 0) {
      this._tools.MostrarMensaje('warning', 'No hay datos para generar el reporte.');
      return;
    }
    
    this.generarDatosReporte();
    this.modalReporteRef = this._tools.openModal(this.modalReporteTpl, 'xl');
  }

  // (Modifica generarDatosReporte)
  generarDatosReporte(): void {
    // 1. Determinar cabeceras estándar basándonos exclusivamente en 'columnasVisibles'
    const standardHeaders: string[] = ['N°'];
    
    if (this.columnasVisibles.paterno) standardHeaders.push('Paterno');
    if (this.columnasVisibles.materno) standardHeaders.push('Materno');
    if (this.columnasVisibles.nombres) standardHeaders.push('Nombres');
    if (this.columnasVisibles.carnet) standardHeaders.push('Carnet');
    if (this.columnasVisibles.edad) standardHeaders.push('Edad');
    if (this.columnasVisibles.fechaNac) standardHeaders.push('Fecha Nacimiento');
    if (this.columnasVisibles.celular) standardHeaders.push('Celular');
    if (this.columnasVisibles.celularTutor) standardHeaders.push('Celular Tutor');
    if (this.columnasVisibles.fechaInscripcion) standardHeaders.push('Fecha Inscripción');
    if (this.columnasVisibles.nombreInstitucion) standardHeaders.push('Institución');
    if (this.columnasVisibles.departamento) standardHeaders.push('Departamento');
    if (this.columnasVisibles.especialidad) standardHeaders.push('Especialidad');
    if (this.columnasVisibles.categoria) standardHeaders.push('Categoría');
    if (this.columnasVisibles.estadoInscripcion) standardHeaders.push('Estado Inscripción');
    if (this.columnasVisibles.tienePago) standardHeaders.push('Tiene Pago');
    if (this.columnasVisibles.monto) standardHeaders.push('Monto');
    if (this.columnasVisibles.estadoPago) standardHeaders.push('Estado Pago');
    if (this.columnasVisibles.correo) standardHeaders.push('Documentación');
    

    // 2. Extraer las claves de los datos dinámicos (especiales)
    const dynamicKeys = new Set<string>();
    const parsedRecords = this.registrosFiltrados.map((r) => {
      const especiales = this.parseDynamicValues(r?.DatosEspeciales);
      Object.keys(especiales).forEach(k => dynamicKeys.add(k));
      return { ...r, especiales };
    });

    const dynamicKeysArray = Array.from(dynamicKeys);
    
    // 3. Renombrar las columnas dinámicas a Col 1, Col 2, Col 3...
    const formattedDynamicHeaders = dynamicKeysArray.map((_, index) => `Col ${index + 1}`);

    // 4. Ensamblar todas las cabeceras (Fijas visibles + Dinámicas siempre presentes)
    this.reporteHeaders = [...standardHeaders, ...formattedDynamicHeaders];

    // 5. Mapear las filas asegurando que coincidan con las cabeceras configuradas
    this.reporteRows = parsedRecords.map((r, i) => {
      const row: any[] = [(i + 1).toString()];

      if (this.columnasVisibles.paterno) row.push(r.Ap_Paterno || '');
      if (this.columnasVisibles.materno) row.push(r.Ap_Materno || '');
      if (this.columnasVisibles.nombres) row.push(r.Nombres || '');
      if (this.columnasVisibles.carnet) row.push(r.Carnet || '');
      if (this.columnasVisibles.edad) row.push(this.edadRegistro(r) ?? '');
      if (this.columnasVisibles.fechaNac) row.push(this.fechaTabla(r.FechaNac) || '');
      if (this.columnasVisibles.celular) row.push(r.Celular || '');
      if (this.columnasVisibles.celularTutor) row.push(r.CelularTutor || '');
      if (this.columnasVisibles.fechaInscripcion) row.push(this.fechaTabla(r.created_at) || '');
      if (this.columnasVisibles.nombreInstitucion) row.push(r.NombreInstitucion || '');
      if (this.columnasVisibles.departamento) row.push(r.Departamento || '');
      if (this.columnasVisibles.especialidad) row.push(r.Especialidad || 'N/A');
      if (this.columnasVisibles.categoria) row.push(r.Categoria || 'N/A');
      
      if (this.columnasVisibles.estadoInscripcion) row.push(r.EstadoInscripcion || '');
      if (this.columnasVisibles.tienePago) row.push(r.TienePago == 1 ? 'Sí' : 'No');
      if (this.columnasVisibles.monto) row.push(r.TienePago == 1 ? (r.Monto || '0') : '');
      if (this.columnasVisibles.estadoPago) row.push(r.TienePago == 1 ? (r.EstadoPago || '') : 'No aplica');
      if (this.columnasVisibles.correo) row.push(r.Correo || 'PENDIENTE');

      // Las columnas dinámicas se agregan sí o sí al final
      dynamicKeysArray.forEach(key => {
        const value = r.especiales[key];
        const valorLimpio = (Array.isArray(value) ? value.join(', ') : (value ?? '').toString())
          .replace(/\r?\n|\r/g, ' / ');
        row.push(valorLimpio);
      });

      return row;
    });
  }

  copiarTablaPortapapeles(): void {
    // Generar formato TSV (Valores Separados por Tabulaciones)
    // Excel interpreta el salto de línea (\n) como nueva fila y el tabulador (\t) como nueva columna.
    
    let textToCopy = this.reporteHeaders.join('\t') + '\n';
    
    this.reporteRows.forEach(row => {
      textToCopy += row.join('\t') + '\n';
    });

    navigator.clipboard.writeText(textToCopy).then(() => {
      this._tools.MostrarMensaje('success', '¡Tabla copiada! Ya puedes pegarla en Excel.');
    }).catch(err => {
      console.error('Error al copiar al portapapeles: ', err);
      this._tools.MostrarMensaje('error', 'No se pudo copiar al portapapeles. Da los permisos al navegador.');
    });
  }




  // Opciones disponibles para ordenar
  columnasOrdenables = [
    { id: 'Ap_Paterno', label: 'Ap. Paterno' },
    { id: 'Ap_Materno', label: 'Ap. Materno' },
    { id: 'Nombres', label: 'Nombres' },
    { id: 'Carnet', label: 'Carnet' },
    { id: 'Edad', label: 'Edad' },
    { id: 'FechaNac', label: 'Fecha Nac.' },
    { id: 'created_at', label: 'Fecha Inscripción' },
    { id: 'NombreInstitucion', label: 'Institución' },
    { id: 'Departamento', label: 'Departamento' },
    { id: 'Especialidad', label: 'Especialidad' },
    { id: 'Categoria', label: 'Categoría' },
    { id: 'EstadoInscripcion', label: 'Est. Inscripción' },
    { id: 'TienePago', label: '¿Aplica Pago?' },
    { id: 'Monto', label: 'Monto' },
    { id: 'EstadoPago', label: 'Estado Pago' },
    { id: 'Correo', label: 'Documentación' }
  ];

  // Estado de los 5 niveles de ordenamiento
  criteriosOrden = [
    { columna: '', direccion: 'asc' as 'asc' | 'desc' },
    { columna: '', direccion: 'asc' as 'asc' | 'desc' },
    { columna: '', direccion: 'asc' as 'asc' | 'desc' },
    { columna: '', direccion: 'asc' as 'asc' | 'desc' },
    { columna: '', direccion: 'asc' as 'asc' | 'desc' }
  ];



  get registrosFiltrados(): any[] {
    return this.ordenarArreglo(this.filtrarRegistros());
  }

  ordenarArreglo(datos: any[]): any[] {
    const activos = this.criteriosOrden.filter(c => c.columna !== '');
    
    // Si no hay ningún select activo, ordena por ID descendente (el más nuevo primero)
    if (activos.length === 0) {
      return datos.sort((a, b) => b.id - a.id);
    }

    return datos.sort((a, b) => {
      for (const crit of activos) {
        let valA = a[crit.columna];
        let valB = b[crit.columna];

        const emptyA = valA == null || valA === '';
        const emptyB = valB == null || valB === '';

        if (emptyA && !emptyB) return crit.direccion === 'asc' ? 1 : -1;
        if (!emptyA && emptyB) return crit.direccion === 'asc' ? -1 : 1;
        if (emptyA && emptyB) continue;

        // Limpieza de espacios accidentales y normalización a mayúsculas para empates precisos
        if (typeof valA === 'string') valA = valA.trim().toUpperCase();
        if (typeof valB === 'string') valB = valB.trim().toUpperCase();

        // Si después de limpiar son idénticos, pasa a la siguiente columna de desempate
        if (valA === valB) continue; 

        const esNumero = !isNaN(Number(valA)) && !isNaN(Number(valB));
        let cmp = esNumero 
            ? Number(valA) - Number(valB) 
            : String(valA).localeCompare(String(valB), undefined, { numeric: true, sensitivity: 'base' });
        
        if (cmp !== 0) return crit.direccion === 'asc' ? cmp : -cmp;
      }
      return 0;
    });
  }

  private valorOrdenable(registro: any, columna: string): string | number | null {
    if (columna === 'Edad') return this.edadRegistro(registro);

    const valor = registro?.[columna];
    if (valor === null || valor === undefined || String(valor).trim() === '') return null;

    if (columna === 'TienePago' || columna === 'Monto') {
      const numero = Number(valor);
      return Number.isFinite(numero) ? numero : null;
    }

    if (columna === 'FechaNac' || columna === 'created_at') {
      const fecha = new Date(String(valor));
      return Number.isNaN(fecha.getTime()) ? null : fecha.getTime();
    }

    return String(valor).trim();
  }

  columnaYaSeleccionada(columna: string, indiceActual: number): boolean {
    return this.criteriosOrden.some((criterio, index) =>
      index !== indiceActual && criterio.columna === columna,
    );
  }

  alCambiarOrden(indiceCambiado?: number): void {
    if (indiceCambiado !== undefined) {
      const columna = this.criteriosOrden[indiceCambiado]?.columna;
      if (columna && this.columnaYaSeleccionada(columna, indiceCambiado)) {
        this.criteriosOrden[indiceCambiado].columna = '';
        this.criteriosOrden[indiceCambiado].direccion = 'asc';
      }
    }
    this.pagin = 1;
    this.generarDatosReporte();
  }

  cambiarDireccionOrden(index: number): void {
    this.criteriosOrden[index].direccion = this.criteriosOrden[index].direccion === 'asc' ? 'desc' : 'asc';
    this.alCambiarOrden();
  }

  limpiarOrden(): void {
    this.criteriosOrden.forEach(c => { c.columna = ''; c.direccion = 'asc'; });
    this.alCambiarOrden();
  }
}
