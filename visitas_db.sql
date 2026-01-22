-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 07-11-2025 a las 17:49:07
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `visitas_db`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `catalogo_aspectos`
--

CREATE TABLE `catalogo_aspectos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `peso` int(11) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evaluaciones`
--

CREATE TABLE `evaluaciones` (
  `id` int(11) NOT NULL,
  `visita_id` int(11) NOT NULL,
  `aspecto_id` int(11) NOT NULL,
  `observacion` text DEFAULT NULL,
  `estado` enum('CUMPLE','NO CUMPLE','PARCIAL','NO EXISTE') DEFAULT 'NO CUMPLE',
  `recurrente` tinyint(1) DEFAULT 0,
  `plazo` date DEFAULT NULL,
  `actividad` varchar(255) DEFAULT NULL,
  `responsable` varchar(255) DEFAULT NULL,
  `evidencia` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `responsable_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `evidencias_cumplimiento`
--

CREATE TABLE `evidencias_cumplimiento` (
  `id` int(11) NOT NULL,
  `evaluacion_id` int(11) NOT NULL,
  `archivo` varchar(255) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `notificaciones_enviadas`
--

CREATE TABLE `notificaciones_enviadas` (
  `id` int(11) NOT NULL,
  `evaluacion_id` int(11) NOT NULL,
  `tipo` enum('30dias','15dias') NOT NULL,
  `enviado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `responsables`
--

CREATE TABLE `responsables` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `correo` varchar(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `responsables`
--

INSERT INTO `responsables` (`id`, `nombre`, `correo`) VALUES
(1, 'Asistente Gerencia', 'asistentegerencia@terminalibague.com'),
(2, 'Asistente Financiero', 'asistentefinanciero@terminalibague.com'),
(3, 'Auxiliar Financiero', 'auxfinanciero@terminalibague.com'),
(4, 'Asistente EDS', 'asistenteeds@terminalibague.com'),
(5, 'Operativo', 'operativo@terminalibague.com'),
(6, 'Edificio', 'edificio@terminalibague.com'),
(7, 'Estación', 'estacion@terminalibague.com'),
(8, 'Financiero', 'financiero@terminalibague.com'),
(9, 'Control Interno', 'controlinterno@terminalibague.com'),
(10, 'Gerencia', 'gerencia@terminalibague.com'),
(11, 'Gestión Documental', 'gestiondocumental@terminalibague.com'),
(12, 'Sistemas', 'system@terminalibague.com'),
(13, 'HSEQ', 'hseq@terminalibague.com'),
(14, 'Gerencia General', 'gerenciageneral@terminalibague.com'),
(15, 'Jurídica', 'juridica@terminalibague.com'),
(16, 'Talento Humano', 'talentohumano@terminalibague.com'),
(17, 'Tesorería', 'tesoreria@terminalibague.com');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `visitas`
--

CREATE TABLE `visitas` (
  `id` int(11) NOT NULL,
  `nombre_visita` varchar(255) NOT NULL,
  `fecha_inicio` date NOT NULL,
  `fecha_fin` date NOT NULL,
  `obs_adicionales` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `catalogo_aspectos`
--
ALTER TABLE `catalogo_aspectos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `evaluaciones`
--
ALTER TABLE `evaluaciones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `visita_id` (`visita_id`),
  ADD KEY `aspecto_id` (`aspecto_id`),
  ADD KEY `fk_responsable` (`responsable_id`);

--
-- Indices de la tabla `evidencias_cumplimiento`
--
ALTER TABLE `evidencias_cumplimiento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluacion_id` (`evaluacion_id`);

--
-- Indices de la tabla `notificaciones_enviadas`
--
ALTER TABLE `notificaciones_enviadas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `evaluacion_id` (`evaluacion_id`);

--
-- Indices de la tabla `responsables`
--
ALTER TABLE `responsables`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `correo` (`correo`);

--
-- Indices de la tabla `visitas`
--
ALTER TABLE `visitas`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `catalogo_aspectos`
--
ALTER TABLE `catalogo_aspectos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `evaluaciones`
--
ALTER TABLE `evaluaciones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `evidencias_cumplimiento`
--
ALTER TABLE `evidencias_cumplimiento`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `notificaciones_enviadas`
--
ALTER TABLE `notificaciones_enviadas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `responsables`
--
ALTER TABLE `responsables`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT de la tabla `visitas`
--
ALTER TABLE `visitas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `evaluaciones`
--
ALTER TABLE `evaluaciones`
  ADD CONSTRAINT `evaluaciones_ibfk_1` FOREIGN KEY (`visita_id`) REFERENCES `visitas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `evaluaciones_ibfk_2` FOREIGN KEY (`aspecto_id`) REFERENCES `catalogo_aspectos` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_responsable` FOREIGN KEY (`responsable_id`) REFERENCES `responsables` (`id`);

--
-- Filtros para la tabla `evidencias_cumplimiento`
--
ALTER TABLE `evidencias_cumplimiento`
  ADD CONSTRAINT `evidencias_cumplimiento_ibfk_1` FOREIGN KEY (`evaluacion_id`) REFERENCES `evaluaciones` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `notificaciones_enviadas`
--
ALTER TABLE `notificaciones_enviadas`
  ADD CONSTRAINT `notificaciones_enviadas_ibfk_1` FOREIGN KEY (`evaluacion_id`) REFERENCES `evaluaciones` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
