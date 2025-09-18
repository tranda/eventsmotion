/**
 * Google Apps Script for Race Data Import to Laravel API
 *
 * This script reads race data from a Google Sheet and sends it to your
 * Laravel backend API using API key authentication.
 *
 * Setup Instructions:
 * 1. Create an API key in your Laravel admin panel
 * 2. Set the API key in the API_KEY constant below
 * 3. Update the API_BASE_URL to match your server
 * 4. Set up your Google Sheet with the expected format
 * 5. Run the sendRaceDataToAPI() function to import data
 */

// Configuration - Update these values for your setup
const API_BASE_URL = 'https://your-domain.com/api'; // Update with your API base URL
const API_KEY = 'ak_your_api_key_here'; // Replace with your actual API key
const EVENT_ID = 1; // Replace with your event ID

/**
 * Main function to send race data to the API
 * Call this function manually or set up a trigger
 */
function sendRaceDataToAPI() {
  try {
    const raceData = extractRaceDataFromSheet();

    if (raceData.length === 0) {
      Logger.log('No race data found in sheet');
      return;
    }

    const response = sendToAPI(raceData);

    if (response.success) {
      Logger.log(`Successfully updated ${raceData.length} races`);
      Logger.log('API Response: ' + response.message);

      // Optionally update a status cell in your sheet
      updateStatusCell('Last Update: ' + new Date().toLocaleString() + ' - SUCCESS');
    } else {
      Logger.log('API Error: ' + response.message);
      updateStatusCell('Last Update: ' + new Date().toLocaleString() + ' - ERROR: ' + response.message);
    }

  } catch (error) {
    Logger.log('Script Error: ' + error.toString());
    updateStatusCell('Last Update: ' + new Date().toLocaleString() + ' - SCRIPT ERROR');
  }
}

/**
 * Extract race data from the current Google Sheet
 * Adjust this function based on your sheet structure
 */
function extractRaceDataFromSheet() {
  const sheet = SpreadsheetApp.getActiveSheet();
  const dataRange = sheet.getDataRange();
  const values = dataRange.getValues();

  // Skip header row (adjust if your headers are in a different row)
  const dataRows = values.slice(1);

  const races = [];

  dataRows.forEach((row, index) => {
    // Adjust column indices based on your sheet structure
    // Example sheet columns: A=Race#, B=Start Time, C=Delay, D=Stage, E=Competition, F=Discipline Info, G=Boat Size, H-Q=Lanes

    const raceNumber = row[0]; // Column A
    const startTime = row[1];   // Column B
    const delay = row[2];       // Column C
    const stage = row[3];       // Column D
    const competition = row[4]; // Column E
    const disciplineInfo = row[5]; // Column F
    const boatSize = row[6];    // Column G

    // Skip empty rows
    if (!raceNumber || !startTime || !stage) {
      return;
    }

    // Extract lane data (columns H through Q = lanes 1-10)
    const lanes = {};
    for (let i = 7; i <= 16; i++) { // Columns H-Q (indices 7-16)
      const laneNumber = i - 6; // Lane numbers 1-10
      const teamName = row[i];

      if (teamName && teamName.trim() !== '') {
        // Check if this cell contains both team name and time (e.g., "Team Austria (02:05.123)")
        const match = teamName.match(/^(.+?)\s*\((\d{2}:\d{2}\.\d{3})\)$/);

        if (match) {
          // Team name with time in parentheses
          lanes[laneNumber] = {
            team: match[1].trim(),
            time: match[2]
          };
        } else {
          // Just team name, no time
          lanes[laneNumber] = {
            team: teamName.trim(),
            time: null
          };
        }
      }
    }

    races.push({
      race_number: parseInt(raceNumber),
      start_time: formatTime(startTime),
      delay: formatDelay(delay),
      stage: stage.toString(),
      competition: competition.toString(),
      discipline_info: disciplineInfo.toString(),
      boat_size: boatSize.toString().toLowerCase(), // Convert to lowercase (small/standard)
      lanes: lanes
    });
  });

  return races;
}

/**
 * Format time for API consumption
 */
function formatTime(timeValue) {
  if (timeValue instanceof Date) {
    return Utilities.formatDate(timeValue, Session.getScriptTimeZone(), 'HH:mm');
  } else if (typeof timeValue === 'string') {
    return timeValue;
  } else {
    return '10:00'; // Default fallback
  }
}

/**
 * Format delay for API consumption
 */
function formatDelay(delayValue) {
  if (delayValue instanceof Date) {
    return Utilities.formatDate(delayValue, Session.getScriptTimeZone(), 'H:mm');
  } else if (typeof delayValue === 'string') {
    return delayValue;
  } else if (typeof delayValue === 'number') {
    // Assume minutes if number
    const hours = Math.floor(delayValue / 60);
    const minutes = delayValue % 60;
    return `${hours}:${minutes.toString().padStart(2, '0')}`;
  } else {
    return '0:00'; // Default fallback
  }
}

/**
 * Send race data to the Laravel API
 */
function sendToAPI(raceData) {
  const url = `${API_BASE_URL}/race-results/bulk-update`;

  const payload = {
    event_id: EVENT_ID,
    races: raceData
  };

  const options = {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-API-Key': API_KEY,  // API key authentication
      'Accept': 'application/json'
    },
    payload: JSON.stringify(payload)
  };

  Logger.log('Sending data to: ' + url);
  Logger.log('Payload: ' + JSON.stringify(payload, null, 2));

  const response = UrlFetchApp.fetch(url, options);
  const responseText = response.getContentText();

  Logger.log('Response status: ' + response.getResponseCode());
  Logger.log('Response body: ' + responseText);

  if (response.getResponseCode() === 200) {
    return JSON.parse(responseText);
  } else {
    // Try to parse error response
    try {
      const errorData = JSON.parse(responseText);
      throw new Error(errorData.message || 'API request failed');
    } catch (parseError) {
      throw new Error(`API request failed with status ${response.getResponseCode()}: ${responseText}`);
    }
  }
}

/**
 * Update a status cell in the sheet (optional)
 * Adjust cell reference as needed
 */
function updateStatusCell(status) {
  try {
    const sheet = SpreadsheetApp.getActiveSheet();
    // Update cell A1 with status - change this to your preferred location
    sheet.getRange('A1').setValue(status);
  } catch (error) {
    Logger.log('Could not update status cell: ' + error.toString());
  }
}

/**
 * Test function to validate your sheet data without sending to API
 */
function testDataExtraction() {
  const raceData = extractRaceDataFromSheet();
  Logger.log('Extracted race data:');
  Logger.log(JSON.stringify(raceData, null, 2));
}

/**
 * Set up automatic triggers (optional)
 * Call this once to set up automatic import every 5 minutes
 */
function setupTriggers() {
  // Delete existing triggers
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => ScriptApp.deleteTrigger(trigger));

  // Create new trigger to run every 5 minutes
  ScriptApp.newTrigger('sendRaceDataToAPI')
    .timeBased()
    .everyMinutes(5)
    .create();

  Logger.log('Automatic trigger set up - will run every 5 minutes');
}

/**
 * Remove all triggers
 */
function removeTriggers() {
  const triggers = ScriptApp.getProjectTriggers();
  triggers.forEach(trigger => ScriptApp.deleteTrigger(trigger));
  Logger.log('All triggers removed');
}